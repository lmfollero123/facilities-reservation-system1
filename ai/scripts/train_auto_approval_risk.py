"""
Train Auto-Approval Risk Assessment Model
Predicts risk level for reservations to determine if they should be auto-approved
or require manual review
"""

import sys
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, classification_report, confusion_matrix
from sklearn.preprocessing import LabelEncoder
import joblib
from datetime import datetime, timedelta
import re

from src.data_loader import DataLoader
import config


def extract_capacity_number(capacity_str):
    """Extract numeric capacity from string"""
    if pd.isna(capacity_str):
        return 100
    if isinstance(capacity_str, (int, float)):
        return int(capacity_str)
    match = re.search(r'(\d+)', str(capacity_str))
    return int(match.group(1)) if match else 100


def extract_time_features(time_slot: str):
    """Extract start hour, end hour, and duration from time slot string"""
    if ' - ' in time_slot:
        try:
            start_time_str, end_time_str = time_slot.split(' - ')
            start_hour = int(start_time_str.split(':')[0])
            end_hour = int(end_time_str.split(':')[0])
        except:
            start_hour = 8
            end_hour = 17
    elif 'Morning' in time_slot:
        start_hour = 8
        end_hour = 12
    elif 'Afternoon' in time_slot:
        start_hour = 13
        end_hour = 17
    elif 'Evening' in time_slot:
        start_hour = 18
        end_hour = 22
    else:
        start_hour = 8
        end_hour = 17
    
    duration_hours = end_hour - start_hour
    return {'start_hour': start_hour, 'end_hour': end_hour, 'duration_hours': duration_hours}


def is_holiday(date_input):
    """Check if date is a holiday"""
    if isinstance(date_input, pd.Timestamp):
        date_str = date_input.strftime('%Y-%m-%d')
    elif isinstance(date_input, str):
        date_str = date_input
    else:
        return 0
    
    month_day = '-'.join(date_str.split('-')[1:3])
    all_holidays = config.PHILIPPINE_HOLIDAYS + config.BARANGAY_CULIAT_EVENTS
    return 1 if month_day in all_holidays else 0


def prepare_features(reservations_df: pd.DataFrame, facilities_df: pd.DataFrame, users_df: pd.DataFrame = None):
    """
    Prepare feature matrix for auto-approval risk assessment
    
    Features:
    - Facility features: facility_id, auto_approve, capacity, max_duration_hours, capacity_threshold
    - Time features: start_hour, end_hour, duration_hours, day_of_week, month, is_weekend, is_holiday
    - User features: user_id, is_verified, user_booking_count, user_violation_count
    - Booking features: expected_attendees, capacity_ratio, is_commercial, duration_ratio, advance_days
    """
    features = []
    
    # Ensure reservation_date is datetime
    if 'reservation_date' in reservations_df.columns:
        if not pd.api.types.is_datetime64_any_dtype(reservations_df['reservation_date']):
            reservations_df['reservation_date'] = pd.to_datetime(reservations_df['reservation_date'], errors='coerce')
    
    # Create user statistics
    user_booking_counts = reservations_df.groupby('user_id').size().to_dict()
    
    # Get user violations if available
    user_violations = {}
    if users_df is not None and 'violation_count' in users_df.columns:
        user_violations = dict(zip(users_df['id'], users_df['violation_count']))
    
    # Get facility features
    facility_dict = {}
    for _, fac in facilities_df.iterrows():
        facility_dict[fac['id']] = {
            'auto_approve': fac.get('auto_approve', 0),
            'capacity': extract_capacity_number(fac.get('capacity', 100)),
            'max_duration_hours': fac.get('max_duration_hours', 8.0) if pd.notna(fac.get('max_duration_hours')) else 8.0,
            'capacity_threshold': fac.get('capacity_threshold', 200) if pd.notna(fac.get('capacity_threshold')) else 200,
        }
    
    # Get current date for advance booking calculation
    current_date = datetime.now().date()
    
    for _, row in reservations_df.iterrows():
        if pd.isna(row.get('reservation_date')):
            continue
        
        # Time features
        time_feat = extract_time_features(row['time_slot'])
        
        # Date features
        reservation_date = row['reservation_date']
        if isinstance(reservation_date, pd.Timestamp):
            reservation_date = reservation_date.date()
        elif isinstance(reservation_date, str):
            reservation_date = datetime.strptime(reservation_date, '%Y-%m-%d').date()
        
        reservation_dt = pd.Timestamp(reservation_date)
        day_of_week = reservation_dt.dayofweek
        month = reservation_dt.month
        is_weekend = 1 if day_of_week >= 5 else 0
        holiday = is_holiday(reservation_dt)
        
        # Calculate advance booking days
        try:
            advance_days = (reservation_date - current_date).days
        except:
            advance_days = 0
        
        # Facility features
        facility_id = row['facility_id']
        facility_info = facility_dict.get(facility_id, {
            'auto_approve': 0,
            'capacity': 100,
            'max_duration_hours': 8.0,
            'capacity_threshold': 200,
        })
        
        # User features
        user_id = row['user_id']
        user_booking_count = user_booking_counts.get(user_id, 0)
        user_violation_count = user_violations.get(user_id, 0)
        is_verified = row.get('user_is_verified', 1) if 'user_is_verified' in row else 1
        
        # Booking features
        expected_attendees = row.get('expected_attendees', 50) if pd.notna(row.get('expected_attendees')) else 50
        capacity = facility_info['capacity']
        capacity_ratio = expected_attendees / capacity if capacity > 0 else 0.5
        duration_hours = time_feat['duration_hours']
        max_duration = facility_info['max_duration_hours']
        duration_ratio = duration_hours / max_duration if max_duration > 0 else 1.0
        is_commercial = 1 if row.get('is_commercial', False) else 0
        
        # Capacity threshold check
        capacity_threshold = facility_info['capacity_threshold']
        within_capacity_threshold = 1 if (capacity_threshold is None or expected_attendees <= capacity_threshold) else 0
        
        # Duration limit check
        within_duration_limit = 1 if (max_duration is None or duration_hours <= max_duration) else 0
        
        # Advance booking window check (60 days default)
        within_advance_window = 1 if (0 <= advance_days <= 60) else 0
        
        feature_row = {
            'facility_id': int(facility_id) if pd.notna(facility_id) else 0,
            'facility_auto_approve': 1 if facility_info['auto_approve'] else 0,
            'facility_capacity': capacity,
            'facility_max_duration_hours': max_duration,
            'facility_capacity_threshold': capacity_threshold if capacity_threshold else 999,
            'user_id': int(user_id) if pd.notna(user_id) else 0,
            'user_is_verified': 1 if is_verified else 0,
            'user_booking_count': user_booking_count,
            'user_violation_count': user_violation_count,
            'start_hour': time_feat['start_hour'],
            'end_hour': time_feat['end_hour'],
            'duration_hours': duration_hours,
            'day_of_week': day_of_week,
            'month': month,
            'is_weekend': is_weekend,
            'is_holiday': holiday,
            'expected_attendees': int(expected_attendees) if pd.notna(expected_attendees) else 50,
            'capacity_ratio': capacity_ratio,
            'duration_ratio': duration_ratio,
            'is_commercial': is_commercial,
            'advance_days': advance_days,
            'within_capacity_threshold': within_capacity_threshold,
            'within_duration_limit': within_duration_limit,
            'within_advance_window': within_advance_window,
        }
        
        features.append(feature_row)
    
    if not features:
        raise ValueError("No valid features extracted from data.")
    
    return pd.DataFrame(features)


def calculate_risk_label(row: pd.Series, reservations_df: pd.DataFrame):
    """
    Calculate risk label for auto-approval
    0 = Low risk (safe to auto-approve)
    1 = High risk (requires manual review)
    
    Based on:
    - Was the reservation auto-approved? (auto_approved = True → Low risk)
    - Was the reservation approved? (approved = Low risk, denied = High risk)
    - For auto-approved reservations: check if they were later cancelled or had issues
    """
    status = str(row.get('status', '')).lower()
    auto_approved = row.get('auto_approved', False)
    
    # If auto-approved and status is approved → Low risk (0)
    if auto_approved and status == 'approved':
        return 0
    
    # If status is denied → High risk (1)
    if status == 'denied':
        return 1
    
    # If status is cancelled → Medium risk, but treat as high for safety
    if status == 'cancelled':
        return 1
    
    # If approved but not auto-approved → Medium risk (manual review was needed)
    if status == 'approved' and not auto_approved:
        return 1
    
    # Default: pending or other → High risk
    return 1


def main():
    """Train auto-approval risk assessment model"""
    print("=" * 60)
    print("Auto-Approval Risk Assessment Model Training")
    print("=" * 60)
    
    loader = DataLoader()
    
    try:
        loader.connect()
        
        print("\n1. Loading data...")
        end_date = datetime.now().strftime('%Y-%m-%d')
        start_date = (datetime.now() - timedelta(days=365)).strftime('%Y-%m-%d')
        
        reservations_df = loader.load_reservations(start_date=start_date, end_date=end_date)
        facilities_df = loader.load_facilities()
        
        # Load users with verification status
        try:
            users_query = "SELECT id, is_verified FROM users"
            cursor = loader.connection.cursor()
            cursor.execute(users_query)
            users_rows = cursor.fetchall()
            users_df = pd.DataFrame([dict(row) for row in users_rows])
            cursor.close()
        except:
            users_df = pd.DataFrame()
        
        # Load user violations count
        try:
            violations_query = """
                SELECT user_id, COUNT(*) as violation_count
                FROM user_violations
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
                  AND severity IN ('high', 'critical')
                GROUP BY user_id
            """
            cursor = loader.connection.cursor()
            cursor.execute(violations_query)
            violations_rows = cursor.fetchall()
            violations_df = pd.DataFrame([dict(row) for row in violations_rows])
            if not violations_df.empty:
                users_df = users_df.merge(violations_df, left_on='id', right_on='user_id', how='left')
                users_df['violation_count'] = users_df['violation_count'].fillna(0)
            cursor.close()
        except:
            pass
        
        # Merge user verification status
        if not users_df.empty and 'is_verified' in users_df.columns:
            reservations_df = reservations_df.merge(
                users_df[['id', 'is_verified']],
                left_on='user_id',
                right_on='id',
                how='left'
            )
            reservations_df['user_is_verified'] = reservations_df['is_verified'].fillna(1)
        
        if reservations_df.empty:
            print("No reservation data found. Cannot train model.")
            return
        
        if len(reservations_df) < 10:
            print("\nWarning: Not enough data for training!")
            print("   Need at least 10 reservations. Current system has rule-based auto-approval.")
            return
        
        print(f"   Loaded {len(reservations_df)} reservations")
        print(f"   Loaded {len(facilities_df)} facilities")
        
        # Load facility auto_approve settings
        try:
            facilities_query = """
                SELECT id, auto_approve, capacity_threshold, max_duration_hours
                FROM facilities
            """
            cursor = loader.connection.cursor()
            cursor.execute(facilities_query)
            facilities_settings = cursor.fetchall()
            facilities_settings_df = pd.DataFrame([dict(row) for row in facilities_settings])
            facilities_df = facilities_df.merge(facilities_settings_df, on='id', how='left')
            cursor.close()
        except:
            # Add defaults if query fails
            facilities_df['auto_approve'] = 0
            facilities_df['capacity_threshold'] = None
            facilities_df['max_duration_hours'] = 8.0
        
        print("\n2. Preparing features...")
        X = prepare_features(reservations_df, facilities_df, users_df)
        print(f"   Features shape: {X.shape}")
        print(f"   Feature columns: {list(X.columns)}")
        
        print("\n3. Calculating risk labels (0=Low risk/Auto-approve, 1=High risk/Manual review)...")
        y = reservations_df.apply(
            lambda row: calculate_risk_label(row, reservations_df),
            axis=1
        )
        
        low_risk_count = (y == 0).sum()
        high_risk_count = (y == 1).sum()
        print(f"   Low risk (auto-approve): {low_risk_count}")
        print(f"   High risk (manual review): {high_risk_count}")
        
        if low_risk_count == 0:
            print("\nWarning: No low-risk samples found!")
            print("   Model may not learn auto-approval patterns effectively.")
            print("   Consider using synthetic data or wait for more auto-approved reservations.")
        
        print("\n4. Encoding categorical features...")
        label_encoders = {}
        categorical_cols = ['facility_id', 'user_id']
        
        for col in categorical_cols:
            if col in X.columns:
                le = LabelEncoder()
                X[col + '_encoded'] = le.fit_transform(X[col].astype(str))
                label_encoders[col] = le
        
        # Drop original categorical columns
        X = X.drop(columns=categorical_cols, errors='ignore')
        
        print("\n5. Splitting data (train/test)...")
        # Only use stratification if both classes have at least 2 samples
        use_stratify = low_risk_count >= 2 and high_risk_count >= 2
        X_train, X_test, y_train, y_test = train_test_split(
            X, y,
            test_size=0.2,
            random_state=42,
            stratify=y if use_stratify else None
        )
        
        print(f"   Train set: {len(X_train)} samples")
        print(f"   Test set: {len(X_test)} samples")
        
        print("\n6. Training Random Forest model...")
        model = RandomForestClassifier(
            n_estimators=100,
            max_depth=10,
            min_samples_leaf=5,
            random_state=42,
            n_jobs=-1,
            class_weight='balanced'  # Handle class imbalance
        )
        model.fit(X_train, y_train)
        print("   Model training complete.")
        
        print("\n7. Evaluating model...")
        y_pred_train = model.predict(X_train)
        y_pred_test = model.predict(X_test)
        
        train_accuracy = accuracy_score(y_train, y_pred_train)
        test_accuracy = accuracy_score(y_test, y_pred_test)
        train_precision = precision_score(y_train, y_pred_train, zero_division=0)
        test_precision = precision_score(y_test, y_pred_test, zero_division=0)
        train_recall = recall_score(y_train, y_pred_train, zero_division=0)
        test_recall = recall_score(y_test, y_pred_test, zero_division=0)
        train_f1 = f1_score(y_train, y_pred_train, zero_division=0)
        test_f1 = f1_score(y_test, y_pred_test, zero_division=0)
        
        print(f"   Train Accuracy: {train_accuracy:.4f}")
        print(f"   Test Accuracy: {test_accuracy:.4f}")
        print(f"   Train Precision: {train_precision:.4f}")
        print(f"   Test Precision: {test_precision:.4f}")
        print(f"   Train Recall: {train_recall:.4f}")
        print(f"   Test Recall: {test_recall:.4f}")
        print(f"   Train F1-Score: {train_f1:.4f}")
        print(f"   Test F1-Score: {test_f1:.4f}")
        
        print("\n   Classification Report (Test Set):")
        unique_classes = sorted(np.unique(y_test))
        if len(unique_classes) == 2:
            print(classification_report(y_test, y_pred_test, target_names=['Low Risk', 'High Risk']))
        else:
            # Only one class in test set
            class_name = 'Low Risk' if 0 in unique_classes else 'High Risk'
            print(f"   Only one class present in test set: {class_name}")
            print(classification_report(y_test, y_pred_test, labels=unique_classes, target_names=[class_name]))
        
        # Feature importance
        print("\n8. Feature Importance (Top 15):")
        feature_importance = pd.DataFrame({
            'feature': X.columns,
            'importance': model.feature_importances_
        }).sort_values('importance', ascending=False)
        print(feature_importance.head(15).to_string(index=False))
        
        # Save model and encoders
        model_path = config.MODELS_DIR / 'auto_approval_risk_model.pkl'
        encoders_path = config.MODELS_DIR / 'auto_approval_risk_encoders.pkl'
        
        joblib.dump(model, model_path)
        joblib.dump(label_encoders, encoders_path)
        
        print(f"\n9. Model saved to: {model_path}")
        print(f"   Encoders saved to: {encoders_path}")
        
    except Exception as e:
        print(f"Error during training: {e}")
        import traceback
        traceback.print_exc()
    finally:
        loader.disconnect()


if __name__ == "__main__":
    main()
