"""
Auto-Approval Risk Assessment Model Inference
Loads trained model and predicts risk level for reservations
"""

import sys
from pathlib import Path
import pandas as pd
import numpy as np
import joblib
from datetime import datetime
import re

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

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


class AutoApprovalRiskModel:
    """Auto-Approval Risk Assessment Model for inference"""
    
    def __init__(self):
        self.model = None
        self.encoders = None
        self.loaded = False
    
    def load_model(self):
        """Load trained model and encoders"""
        try:
            model_path = config.MODELS_DIR / 'auto_approval_risk_model.pkl'
            encoders_path = config.MODELS_DIR / 'auto_approval_risk_encoders.pkl'
            
            if not model_path.exists():
                raise FileNotFoundError(f"Model file not found: {model_path}")
            
            self.model = joblib.load(model_path)
            self.encoders = joblib.load(encoders_path)
            self.loaded = True
            return True
        except Exception as e:
            print(f"Error loading model: {e}")
            return False
    
    def predict_risk(self, features: dict):
        """
        Predict risk level for a reservation
        
        Args:
            features: Dictionary with reservation and facility features
        
        Returns:
            Dictionary with:
                - risk_level: 0 (Low risk/Auto-approve) or 1 (High risk/Manual review)
                - risk_probability: Probability of high risk (0-1)
                - confidence: Confidence in prediction
        """
        if not self.loaded:
            if not self.load_model():
                return {
                    'risk_level': 1,  # Default to high risk if model not available
                    'risk_probability': 0.5,
                    'confidence': 0.0
                }
        
        try:
            # Prepare feature vector
            feature_dict = {
                'facility_id': features.get('facility_id', 0),
                'facility_auto_approve': 1 if features.get('facility_auto_approve', False) else 0,
                'facility_capacity': features.get('facility_capacity', 100),
                'facility_max_duration_hours': features.get('facility_max_duration_hours', 8.0),
                'facility_capacity_threshold': features.get('facility_capacity_threshold', 200),
                'user_id': features.get('user_id', 0),
                'user_is_verified': 1 if features.get('user_is_verified', True) else 0,
                'user_booking_count': features.get('user_booking_count', 0),
                'user_violation_count': features.get('user_violation_count', 0),
                'start_hour': features.get('start_hour', 12),
                'end_hour': features.get('end_hour', 17),
                'duration_hours': features.get('duration_hours', 4),
                'day_of_week': features.get('day_of_week', 0),
                'month': features.get('month', 1),
                'is_weekend': features.get('is_weekend', 0),
                'is_holiday': features.get('is_holiday', 0),
                'expected_attendees': features.get('expected_attendees', 50),
                'capacity_ratio': features.get('capacity_ratio', 0.5),
                'duration_ratio': features.get('duration_ratio', 0.5),
                'is_commercial': 1 if features.get('is_commercial', False) else 0,
                'advance_days': features.get('advance_days', 0),
                'within_capacity_threshold': 1 if features.get('within_capacity_threshold', True) else 0,
                'within_duration_limit': 1 if features.get('within_duration_limit', True) else 0,
                'within_advance_window': 1 if features.get('within_advance_window', True) else 0,
            }
            
            # Encode categorical features
            if 'facility_id' in self.encoders:
                facility_id_str = str(feature_dict['facility_id'])
                if facility_id_str in self.encoders['facility_id'].classes_:
                    feature_dict['facility_id_encoded'] = self.encoders['facility_id'].transform([facility_id_str])[0]
                else:
                    feature_dict['facility_id_encoded'] = 0
            else:
                feature_dict['facility_id_encoded'] = 0
            
            if 'user_id' in self.encoders:
                user_id_str = str(feature_dict['user_id'])
                if user_id_str in self.encoders['user_id'].classes_:
                    feature_dict['user_id_encoded'] = self.encoders['user_id'].transform([user_id_str])[0]
                else:
                    feature_dict['user_id_encoded'] = 0
            else:
                feature_dict['user_id_encoded'] = 0
            
            # Remove original categorical columns
            feature_dict.pop('facility_id', None)
            feature_dict.pop('user_id', None)
            
            # Convert to DataFrame
            feature_df = pd.DataFrame([feature_dict])
            
            # Ensure all columns from training are present
            expected_features = [
                'facility_auto_approve', 'facility_capacity', 'facility_max_duration_hours',
                'facility_capacity_threshold', 'user_is_verified', 'user_booking_count',
                'user_violation_count', 'start_hour', 'end_hour', 'duration_hours',
                'day_of_week', 'month', 'is_weekend', 'is_holiday', 'expected_attendees',
                'capacity_ratio', 'duration_ratio', 'is_commercial', 'advance_days',
                'within_capacity_threshold', 'within_duration_limit', 'within_advance_window',
                'facility_id_encoded', 'user_id_encoded'
            ]
            
            # Add missing columns with default values
            for col in expected_features:
                if col not in feature_df.columns:
                    feature_df[col] = 0
            
            # Reorder columns to match training
            feature_df = feature_df[expected_features]
            
            # Predict
            risk_level = self.model.predict(feature_df)[0]
            risk_proba = self.model.predict_proba(feature_df)[0]
            
            # Get probability of high risk (class 1)
            high_risk_prob = risk_proba[1] if len(risk_proba) > 1 else risk_proba[0]
            confidence = max(risk_proba)
            
            return {
                'risk_level': int(risk_level),
                'risk_probability': float(high_risk_prob),
                'confidence': float(confidence),
                'is_low_risk': bool(risk_level == 0),  # Convert numpy bool to Python bool for JSON
                'is_high_risk': bool(risk_level == 1),  # Convert numpy bool to Python bool for JSON
            }
        
        except Exception as e:
            print(f"Error predicting risk: {e}")
            return {
                'risk_level': 1,
                'risk_probability': 0.5,
                'confidence': 0.0
            }
    
    def assess_reservation_risk(self, facility_id: int, user_id: int, reservation_date: str,
                              time_slot: str, expected_attendees: int, is_commercial: bool,
                              facility_auto_approve: bool, facility_capacity: int,
                              facility_max_duration_hours: float, facility_capacity_threshold: int,
                              user_is_verified: bool, user_booking_count: int,
                              user_violation_count: int = 0):
        """
        Assess risk for a reservation request
        
        Args:
            facility_id: Facility ID
            user_id: User ID
            reservation_date: Reservation date (YYYY-MM-DD)
            time_slot: Time slot string (e.g., "08:00 - 12:00")
            expected_attendees: Expected number of attendees
            is_commercial: Whether reservation is commercial
            facility_auto_approve: Whether facility allows auto-approval
            facility_capacity: Facility capacity
            facility_max_duration_hours: Maximum duration allowed
            facility_capacity_threshold: Capacity threshold for auto-approval
            user_is_verified: Whether user is verified
            user_booking_count: User's total booking count
            user_violation_count: User's violation count
        
        Returns:
            Dictionary with risk assessment results
        """
        # Parse reservation date
        try:
            res_date = pd.to_datetime(reservation_date)
            day_of_week = res_date.dayofweek
            month = res_date.month
            is_weekend = 1 if day_of_week >= 5 else 0
            holiday = is_holiday(res_date)
            
            # Calculate advance booking days
            current_date = datetime.now().date()
            if isinstance(res_date, pd.Timestamp):
                res_date_obj = res_date.date()
            else:
                res_date_obj = datetime.strptime(reservation_date, '%Y-%m-%d').date()
            advance_days = (res_date_obj - current_date).days
        except:
            day_of_week = 0
            month = 1
            is_weekend = 0
            holiday = 0
            advance_days = 0
        
        # Extract time features
        time_feat = extract_time_features(time_slot)
        duration_hours = time_feat['duration_hours']
        
        # Calculate ratios and checks
        capacity = extract_capacity_number(facility_capacity)
        capacity_ratio = expected_attendees / capacity if capacity > 0 else 0.5
        duration_ratio = duration_hours / facility_max_duration_hours if facility_max_duration_hours > 0 else 1.0
        within_capacity_threshold = 1 if (facility_capacity_threshold is None or expected_attendees <= facility_capacity_threshold) else 0
        within_duration_limit = 1 if (facility_max_duration_hours is None or duration_hours <= facility_max_duration_hours) else 0
        within_advance_window = 1 if (0 <= advance_days <= 60) else 0
        
        # Prepare features
        features = {
            'facility_id': facility_id,
            'facility_auto_approve': facility_auto_approve,
            'facility_capacity': capacity,
            'facility_max_duration_hours': facility_max_duration_hours,
            'facility_capacity_threshold': facility_capacity_threshold if facility_capacity_threshold else 999,
            'user_id': user_id,
            'user_is_verified': user_is_verified,
            'user_booking_count': user_booking_count,
            'user_violation_count': user_violation_count,
            'start_hour': time_feat['start_hour'],
            'end_hour': time_feat['end_hour'],
            'duration_hours': duration_hours,
            'day_of_week': day_of_week,
            'month': month,
            'is_weekend': is_weekend,
            'is_holiday': holiday,
            'expected_attendees': expected_attendees,
            'capacity_ratio': capacity_ratio,
            'duration_ratio': duration_ratio,
            'is_commercial': is_commercial,
            'advance_days': advance_days,
            'within_capacity_threshold': within_capacity_threshold,
            'within_duration_limit': within_duration_limit,
            'within_advance_window': within_advance_window,
        }
        
        return self.predict_risk(features)


# Global model instance
_model_instance = None


def get_risk_model():
    """Get or create global model instance"""
    global _model_instance
    if _model_instance is None:
        _model_instance = AutoApprovalRiskModel()
    return _model_instance
