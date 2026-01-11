"""
Train Facility Recommendation Model
Uses historical booking data to learn which facilities users prefer for different purposes
"""

import sys
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split
from sklearn.ensemble import RandomForestRegressor
from sklearn.metrics import mean_squared_error, mean_absolute_error, r2_score
from sklearn.preprocessing import LabelEncoder
import joblib
from datetime import datetime, timedelta
import os
import re

from src.data_loader import DataLoader
import config


def extract_capacity_number(capacity_str):
    """Extract numeric capacity from string"""
    if pd.isna(capacity_str):
        return 100  # Default
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


def extract_purpose_keywords(purpose: str):
    """Extract common keywords from purpose text"""
    if pd.isna(purpose):
        return {}
    
    purpose_lower = str(purpose).lower()
    
    # Common event types
    keywords = {
        'meeting': 1 if any(word in purpose_lower for word in ['meeting', 'conference', 'assembly']) else 0,
        'celebration': 1 if any(word in purpose_lower for word in ['celebration', 'party', 'fiesta', 'festival']) else 0,
        'sports': 1 if any(word in purpose_lower for word in ['sports', 'game', 'tournament', 'basketball', 'volleyball']) else 0,
        'education': 1 if any(word in purpose_lower for word in ['education', 'training', 'seminar', 'workshop', 'class']) else 0,
        'religious': 1 if any(word in purpose_lower for word in ['religious', 'mass', 'prayer', 'worship']) else 0,
        'community': 1 if any(word in purpose_lower for word in ['community', 'barangay', 'general assembly', 'town hall']) else 0,
        'feeding': 1 if any(word in purpose_lower for word in ['feeding', 'food', 'distribution']) else 0,
        'commercial': 1 if any(word in purpose_lower for word in ['commercial', 'business', 'sale', 'market']) else 0,
    }
    
    return keywords


def is_holiday(date_input):
    """Check if date is a holiday (Philippines + Barangay Culiat)"""
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
    Prepare feature matrix for facility recommendation model
    
    Features:
    - User features: user_id (encoded), booking history count
    - Facility features: facility_id (encoded), capacity, amenities count
    - Purpose features: purpose keywords (meeting, celebration, sports, etc.)
    - Time features: start_hour, end_hour, duration, day_of_week, month, is_weekend, is_holiday
    - Booking features: expected_attendees, capacity_ratio, is_commercial
    """
    features = []
    
    # Ensure reservation_date is datetime
    if 'reservation_date' in reservations_df.columns:
        if not pd.api.types.is_datetime64_any_dtype(reservations_df['reservation_date']):
            reservations_df['reservation_date'] = pd.to_datetime(reservations_df['reservation_date'], errors='coerce')
    
    # Create user booking history count
    user_booking_counts = reservations_df.groupby('user_id').size().to_dict()
    
    for _, row in reservations_df.iterrows():
        if pd.isna(row.get('reservation_date')):
            continue
        
        # Time features
        time_feat = extract_time_features(row['time_slot'])
        
        # Date features
        reservation_date = row['reservation_date']
        day_of_week = reservation_date.dayofweek  # 0=Monday, 6=Sunday
        month = reservation_date.month
        is_weekend = 1 if day_of_week >= 5 else 0
        holiday = is_holiday(reservation_date)
        
        # Facility features
        facility_info = facilities_df[facilities_df['id'] == row['facility_id']]
        if not facility_info.empty:
            capacity = extract_capacity_number(facility_info.iloc[0]['capacity'])
            amenities = facility_info.iloc[0].get('amenities', '')
            amenities_count = len(str(amenities).split(',')) if amenities else 0
        else:
            capacity = 100
            amenities_count = 0
        
        # User features
        user_id = row['user_id']
        user_booking_count = user_booking_counts.get(user_id, 0)
        
        # Purpose features
        purpose = row.get('purpose', '')
        purpose_keywords = extract_purpose_keywords(purpose)
        
        # Booking features
        expected_attendees = row.get('expected_attendees', 50) if pd.notna(row.get('expected_attendees')) else 50
        capacity_ratio = expected_attendees / capacity if capacity > 0 else 0.5
        is_commercial = 1 if row.get('is_commercial', False) else 0
        
        feature_row = {
            'user_id': int(user_id) if pd.notna(user_id) else 0,
            'facility_id': int(row['facility_id']) if pd.notna(row['facility_id']) else 0,
            'capacity': capacity,
            'amenities_count': amenities_count,
            'start_hour': time_feat['start_hour'],
            'end_hour': time_feat['end_hour'],
            'duration_hours': time_feat['duration_hours'],
            'day_of_week': day_of_week,
            'month': month,
            'is_weekend': is_weekend,
            'is_holiday': holiday,
            'expected_attendees': int(expected_attendees) if pd.notna(expected_attendees) else 50,
            'capacity_ratio': capacity_ratio,
            'is_commercial': is_commercial,
            'user_booking_count': user_booking_count,
        }
        
        # Add purpose keywords
        feature_row.update(purpose_keywords)
        
        features.append(feature_row)
    
    if not features:
        raise ValueError("No valid features extracted from data.")
    
    return pd.DataFrame(features)


def calculate_relevance_score(row: pd.Series, reservations_df: pd.DataFrame):
    """
    Calculate relevance score for a facility-user-purpose combination
    Score based on:
    - How often this facility was booked for similar purposes (higher = more relevant)
    - How often this user booked this facility (higher = more relevant)
    - Recency of bookings (more recent = higher score)
    """
    score = 0.0
    
    # Base score: facility was booked (1.0)
    score += 1.0
    
    # Purpose similarity: count similar purpose bookings
    purpose = str(row.get('purpose', '')).lower()
    similar_purpose_count = 0
    for _, other_row in reservations_df.iterrows():
        if other_row['facility_id'] == row['facility_id']:
            other_purpose = str(other_row.get('purpose', '')).lower()
            # Simple similarity: check for common words
            common_words = set(purpose.split()) & set(other_purpose.split())
            if len(common_words) > 0:
                similar_purpose_count += 1
    
    score += min(similar_purpose_count * 0.1, 2.0)  # Max 2.0 points
    
    # User preference: count how many times this user booked this facility
    user_facility_count = len(reservations_df[
        (reservations_df['user_id'] == row['user_id']) &
        (reservations_df['facility_id'] == row['facility_id'])
    ])
    score += min(user_facility_count * 0.2, 1.0)  # Max 1.0 points
    
    # Recency: more recent bookings get higher scores
    if 'reservation_date' in row and pd.notna(row['reservation_date']):
        days_ago = (datetime.now() - row['reservation_date']).days
        recency_score = max(0, 1.0 - (days_ago / 365.0))  # Decay over 1 year
        score += recency_score * 0.5  # Max 0.5 points
    
    return min(score, 5.0)  # Cap at 5.0


def main():
    """Train facility recommendation model"""
    print("=" * 60)
    print("Facility Recommendation Model Training")
    print("=" * 60)
    
    loader = DataLoader()
    
    try:
        loader.connect()
        
        print("\n1. Loading data...")
        end_date = datetime.now().strftime('%Y-%m-%d')
        start_date = (datetime.now() - timedelta(days=365)).strftime('%Y-%m-%d')
        
        reservations_df = loader.load_reservations(start_date=start_date, end_date=end_date)
        facilities_df = loader.load_facilities()
        
        # Load users for additional features (optional)
        try:
            users_query = "SELECT id, role FROM users"
            cursor = loader.connection.cursor()
            cursor.execute(users_query)
            users_rows = cursor.fetchall()
            users_df = pd.DataFrame([dict(row) for row in users_rows])
            cursor.close()
        except:
            users_df = pd.DataFrame()
        
        if reservations_df.empty:
            print("No reservation data found. Cannot train model.")
            return
        
        if len(reservations_df) < 5:
            print("\nWarning: Not enough data for training!")
            print("   Need at least 5 reservations. Current system has rule-based recommendations.")
            return
        
        print(f"   Loaded {len(reservations_df)} reservations")
        print(f"   Loaded {len(facilities_df)} facilities")
        
        # Filter to only approved reservations (successful bookings)
        reservations_df = reservations_df[reservations_df['status'] == 'approved'].copy()
        print(f"   Using {len(reservations_df)} approved reservations for training")
        
        if len(reservations_df) < 5:
            print("\nWarning: Not enough approved reservations for training!")
            print("   Need at least 5 approved reservations.")
            return
        
        print("\n2. Preparing features...")
        X = prepare_features(reservations_df, facilities_df, users_df)
        print(f"   Features shape: {X.shape}")
        print(f"   Feature columns: {list(X.columns)}")
        
        print("\n3. Calculating relevance scores...")
        y = reservations_df.apply(
            lambda row: calculate_relevance_score(row, reservations_df),
            axis=1
        )
        
        print(f"   Relevance scores - Min: {y.min():.2f}, Max: {y.max():.2f}, Mean: {y.mean():.2f}")
        
        # Encode categorical features
        print("\n4. Encoding categorical features...")
        label_encoders = {}
        categorical_cols = ['user_id', 'facility_id']
        
        for col in categorical_cols:
            if col in X.columns:
                le = LabelEncoder()
                X[col + '_encoded'] = le.fit_transform(X[col].astype(str))
                label_encoders[col] = le
        
        # Drop original categorical columns
        X = X.drop(columns=categorical_cols, errors='ignore')
        
        print("\n5. Splitting data (train/test)...")
        X_train, X_test, y_train, y_test = train_test_split(
            X, y,
            test_size=0.2,
            random_state=42
        )
        
        print(f"   Train set: {len(X_train)} samples")
        print(f"   Test set: {len(X_test)} samples")
        
        print("\n6. Training Random Forest model...")
        model = RandomForestRegressor(
            n_estimators=100,
            max_depth=10,
            min_samples_leaf=5,
            random_state=42,
            n_jobs=-1
        )
        model.fit(X_train, y_train)
        print("   Model training complete.")
        
        print("\n7. Evaluating model...")
        y_pred_train = model.predict(X_train)
        y_pred_test = model.predict(X_test)
        
        train_rmse = np.sqrt(mean_squared_error(y_train, y_pred_train))
        test_rmse = np.sqrt(mean_squared_error(y_test, y_pred_test))
        train_mae = mean_absolute_error(y_train, y_pred_train)
        test_mae = mean_absolute_error(y_test, y_pred_test)
        train_r2 = r2_score(y_train, y_pred_train)
        test_r2 = r2_score(y_test, y_pred_test)
        
        print(f"   Train RMSE: {train_rmse:.4f}")
        print(f"   Test RMSE: {test_rmse:.4f}")
        print(f"   Train MAE: {train_mae:.4f}")
        print(f"   Test MAE: {test_mae:.4f}")
        print(f"   Train R²: {train_r2:.4f}")
        print(f"   Test R²: {test_r2:.4f}")
        
        # Feature importance
        print("\n8. Feature Importance (Top 10):")
        feature_importance = pd.DataFrame({
            'feature': X.columns,
            'importance': model.feature_importances_
        }).sort_values('importance', ascending=False)
        print(feature_importance.head(10).to_string(index=False))
        
        # Save model and encoders
        model_path = os.path.join(config.MODELS_DIR, 'facility_recommendation_model.pkl')
        encoders_path = os.path.join(config.MODELS_DIR, 'facility_recommendation_encoders.pkl')
        
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
