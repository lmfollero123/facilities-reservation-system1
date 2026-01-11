"""
Train Conflict Detection Model
Predicts booking conflicts based on historical patterns
"""

import sys
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import LabelEncoder
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix
import joblib
from datetime import datetime, timedelta
import config

# Import data loader
from src.data_loader import DataLoader


def extract_time_features(time_slot: str) -> dict:
    """
    Extract features from time slot string (e.g., "08:00 - 12:00")
    
    Returns:
        Dictionary with start_hour, end_hour, duration_hours
    """
    try:
        if pd.isna(time_slot):
            return {'start_hour': 12, 'end_hour': 17, 'duration_hours': 4}
            
        parts = str(time_slot).split(' - ')
        if len(parts) != 2:
            return {'start_hour': 12, 'end_hour': 17, 'duration_hours': 4}
        
        start_time = datetime.strptime(parts[0].strip(), '%H:%M')
        end_time = datetime.strptime(parts[1].strip(), '%H:%M')
        
        start_hour = start_time.hour
        end_hour = end_time.hour
        duration = (end_time - start_time).total_seconds() / 3600
        
        return {
            'start_hour': start_hour,
            'end_hour': end_hour,
            'duration_hours': duration
        }
    except:
        return {'start_hour': 12, 'end_hour': 17, 'duration_hours': 4}


def extract_capacity_number(capacity_str: str) -> int:
    """Extract numeric capacity from string"""
    if pd.isna(capacity_str):
        return 100  # Default
    import re
    match = re.search(r'(\d+)', str(capacity_str))
    return int(match.group(1)) if match else 100


def is_holiday(date_obj) -> int:
    """Check if date is a holiday (Philippines + Barangay Culiat)"""
    try:
        # Handle both string and datetime inputs
        if isinstance(date_obj, pd.Timestamp):
            date_str = date_obj.strftime('%Y-%m-%d')
        elif isinstance(date_obj, datetime):
            date_str = date_obj.strftime('%Y-%m-%d')
        else:
            date_str = str(date_obj)
        
        date_part = date_str.split()[0] if ' ' in date_str else date_str
        parts = date_part.split('-')
        if len(parts) >= 3:
            month_day = f"{parts[1]}-{parts[2]}"
        else:
            return 0
        
        all_holidays = config.PHILIPPINE_HOLIDAYS + config.BARANGAY_CULIAT_EVENTS
        return 1 if month_day in all_holidays else 0
    except:
        return 0


def prepare_features(df: pd.DataFrame, facilities_df: pd.DataFrame) -> pd.DataFrame:
    """
    Prepare feature matrix for conflict detection model
    
    Args:
        df: Reservation DataFrame
        facilities_df: Facilities DataFrame
        
    Returns:
        Feature matrix DataFrame
    """
    features = []
    
    # Ensure reservation_date is datetime type - convert the entire column first
    if 'reservation_date' in df.columns:
        df = df.copy()  # Work on a copy to avoid warnings
        df['reservation_date'] = pd.to_datetime(df['reservation_date'], errors='coerce')
        # Remove rows with invalid dates
        df = df[~df['reservation_date'].isna()]
    
    print(f"   Processing {len(df)} reservations...")
    
    for idx, row in df.iterrows():
        try:
            # Get reservation date - should already be datetime
            reservation_date = row['reservation_date']
            
            # Skip if invalid
            if pd.isna(reservation_date):
                continue
            
            # Ensure it's a Timestamp
            if not isinstance(reservation_date, pd.Timestamp):
                reservation_date = pd.to_datetime(reservation_date, errors='coerce')
                if pd.isna(reservation_date):
                    continue
            
            # Time features
            time_slot = row.get('time_slot', '12:00 - 17:00')
            time_feat = extract_time_features(time_slot)
            
            # Date features
            day_of_week = reservation_date.dayofweek  # 0=Monday, 6=Sunday
            month = reservation_date.month
            is_weekend = 1 if day_of_week >= 5 else 0
            holiday = is_holiday(reservation_date)
        
            # Facility features
            facility_id = row.get('facility_id', 0)
            facility_info = facilities_df[facilities_df['id'] == facility_id]
            if not facility_info.empty:
                capacity = extract_capacity_number(facility_info.iloc[0]['capacity'])
            else:
                capacity = 100
            
            # User features
            expected_attendees = row.get('expected_attendees', 50)
            if pd.isna(expected_attendees):
                expected_attendees = 50
            expected_attendees = int(expected_attendees)
            capacity_ratio = expected_attendees / capacity if capacity > 0 else 0.5
            
            # Commercial flag
            is_commercial = 1 if row.get('is_commercial', False) else 0
            
            feature_row = {
                'facility_id': int(facility_id) if pd.notna(facility_id) else 0,
                'start_hour': time_feat['start_hour'],
                'end_hour': time_feat['end_hour'],
                'duration_hours': time_feat['duration_hours'],
                'day_of_week': day_of_week,
                'month': month,
                'is_weekend': is_weekend,
                'is_holiday': holiday,
                'capacity': capacity,
                'expected_attendees': expected_attendees,
                'capacity_ratio': capacity_ratio,
                'is_commercial': is_commercial,
            }
            
            features.append(feature_row)
        except Exception as e:
            print(f"   Warning: Skipping row {idx} due to error: {e}")
            continue
    
    if not features:
        raise ValueError("No valid features extracted from data. Check date format and data quality.")
    
    return pd.DataFrame(features)


def calculate_conflict_label(row: pd.Series, reservations_df: pd.DataFrame) -> int:
    """
    Calculate if this reservation has a conflict (1) or not (0)
    This is a simplified version - in practice, you'd check for overlapping time slots
    """
    try:
        # Check if there are other approved reservations on same facility/date
        same_facility_date = reservations_df[
            (reservations_df['facility_id'] == row['facility_id']) &
            (reservations_df['reservation_date'] == row['reservation_date']) &
            (reservations_df['id'] != row['id']) &
            (reservations_df['status'] == 'approved')
        ]
        
        # Simple conflict: if there are other approved reservations, it's a conflict
        # (In reality, we'd check time overlap more carefully)
        return 1 if len(same_facility_date) > 0 else 0
    except:
        return 0


def main():
    """Train conflict detection model"""
    print("=" * 60)
    print("Conflict Detection Model Training")
    print("=" * 60)
    
    # Load data
    loader = DataLoader()
    
    try:
        loader.connect()
        
        print("\n1. Loading data...")
        end_date = datetime.now().strftime('%Y-%m-%d')
        start_date = (datetime.now() - timedelta(days=365)).strftime('%Y-%m-%d')
        
        reservations_df = loader.load_reservations(start_date=start_date, end_date=end_date)
        facilities_df = loader.load_facilities()
        
        print(f"   Loaded {len(reservations_df)} reservations")
        print(f"   Loaded {len(facilities_df)} facilities")
        
        if len(reservations_df) < 10:
            print("\nWarning: Not enough data for training!")
            print("   Need at least 10 reservations. Current system has rule-based conflict detection.")
            print("   Continue collecting data before training ML model.")
            return
        
        print("\n2. Preparing features...")
        # Dates should already be converted in data_loader, but ensure they're datetime
        reservations_df = reservations_df.copy()
        if 'reservation_date' in reservations_df.columns:
            # Ensure datetime type
            if not pd.api.types.is_datetime64_any_dtype(reservations_df['reservation_date']):
                reservations_df['reservation_date'] = pd.to_datetime(reservations_df['reservation_date'], errors='coerce')
            
            # Remove rows with invalid dates
            invalid_count = reservations_df['reservation_date'].isna().sum()
            if invalid_count > 0:
                print(f"   Warning: {invalid_count} reservations with invalid dates")
            reservations_df = reservations_df[~reservations_df['reservation_date'].isna()]
            print(f"   Valid reservations: {len(reservations_df)}")
        
        if len(reservations_df) == 0:
            print("\nError: No valid reservations with valid dates!")
            print("   Please check the reservation_date format in the database.")
            return
        
        X = prepare_features(reservations_df, facilities_df)
        print(f"   Features shape: {X.shape}")
        
        if len(X) == 0:
            print("\nError: No features extracted!")
            return
        
        print("\n3. Calculating labels (conflict = 1, no conflict = 0)...")
        # For now, use a simplified conflict detection
        # In production, you'd use the actual conflict data from get_historical_conflicts()
        y = reservations_df.apply(
            lambda row: calculate_conflict_label(row, reservations_df), 
            axis=1
        )
        
        # Align X and y (in case some rows were skipped in prepare_features)
        min_len = min(len(X), len(y))
        X = X.iloc[:min_len]
        y = y.iloc[:min_len]
        
        conflict_count = y.sum()
        no_conflict_count = len(y) - conflict_count
        print(f"   Conflicts: {conflict_count}")
        print(f"   No conflicts: {no_conflict_count}")
        
        if conflict_count == 0:
            print("\nWarning: No conflicts found in data!")
            print("   Model may not learn conflict patterns effectively.")
            print("   Consider using synthetic data or wait for more reservations.")
        
        print("\n4. Splitting data (train/test)...")
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, 
            test_size=config.CONFLICT_DETECTION_PARAMS['test_size'],
            random_state=config.CONFLICT_DETECTION_PARAMS['random_state'],
            stratify=y if conflict_count > 0 and no_conflict_count > 0 else None
        )
        
        print(f"   Train set: {len(X_train)} samples")
        print(f"   Test set: {len(X_test)} samples")
        
        print("\n5. Training Random Forest model...")
        model = RandomForestClassifier(
            n_estimators=config.CONFLICT_DETECTION_PARAMS['n_estimators'],
            max_depth=config.CONFLICT_DETECTION_PARAMS['max_depth'],
            random_state=config.CONFLICT_DETECTION_PARAMS['random_state'],
            n_jobs=-1
        )
        
        model.fit(X_train, y_train)
        print("   Training complete!")
        
        print("\n6. Evaluating model...")
        y_pred = model.predict(X_test)
        accuracy = accuracy_score(y_test, y_pred)
        print(f"   Accuracy: {accuracy:.4f}")
        
        print("\n   Classification Report:")
        print(classification_report(y_test, y_pred))
        
        print("\n   Feature Importance (Top 10):")
        feature_importance = pd.DataFrame({
            'feature': X.columns,
            'importance': model.feature_importances_
        }).sort_values('importance', ascending=False)
        print(feature_importance.head(10).to_string(index=False))
        
        print("\n7. Saving model...")
        model_path = config.MODELS_DIR / 'conflict_detection.pkl'
        joblib.dump(model, model_path)
        print(f"   Model saved to: {model_path}")
        
        # Save feature names for inference
        feature_names_path = config.MODELS_DIR / 'conflict_detection_features.pkl'
        joblib.dump(list(X.columns), feature_names_path)
        print(f"   Feature names saved to: {feature_names_path}")
        
        print("\n" + "=" * 60)
        print("Training Complete!")
        print("=" * 60)
        print(f"\nModel saved to: {model_path}")
        print(f"\nNext steps:")
        print("  1. Test the model with: python scripts/test_conflict_detection.py")
        print("  2. Integrate with PHP system via API")
        print("  3. Continue training as more data becomes available")
        
    except Exception as e:
        print(f"\nError during training: {e}")
        import traceback
        traceback.print_exc()
    finally:
        loader.disconnect()


if __name__ == '__main__':
    main()
