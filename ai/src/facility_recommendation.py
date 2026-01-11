"""
Facility Recommendation Model Inference
Loads trained model and provides recommendations for new queries
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


def extract_purpose_keywords(purpose: str):
    """Extract common keywords from purpose text"""
    if pd.isna(purpose) or not purpose:
        return {}
    
    purpose_lower = str(purpose).lower()
    
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


class FacilityRecommendationModel:
    """Facility Recommendation Model for inference"""
    
    def __init__(self):
        self.model = None
        self.encoders = None
        self.loaded = False
    
    def load_model(self):
        """Load trained model and encoders"""
        try:
            model_path = config.MODELS_DIR / 'facility_recommendation_model.pkl'
            encoders_path = config.MODELS_DIR / 'facility_recommendation_encoders.pkl'
            
            if not model_path.exists():
                raise FileNotFoundError(f"Model file not found: {model_path}")
            
            self.model = joblib.load(model_path)
            self.encoders = joblib.load(encoders_path)
            self.loaded = True
            return True
        except Exception as e:
            print(f"Error loading model: {e}")
            return False
    
    def predict_relevance(self, features: dict, user_booking_count: int = 0):
        """
        Predict relevance score for a facility-user-purpose combination
        
        Args:
            features: Dictionary with facility and booking features
            user_booking_count: Number of times user has booked facilities
        
        Returns:
            Relevance score (0-5)
        """
        if not self.loaded:
            if not self.load_model():
                return 0.0
        
        try:
            # Prepare feature vector
            feature_dict = {
                'user_id': features.get('user_id', 0),
                'facility_id': features.get('facility_id', 0),
                'capacity': features.get('capacity', 100),
                'amenities_count': features.get('amenities_count', 0),
                'start_hour': features.get('start_hour', 12),
                'end_hour': features.get('end_hour', 17),
                'duration_hours': features.get('duration_hours', 4),
                'day_of_week': features.get('day_of_week', 0),
                'month': features.get('month', 1),
                'is_weekend': features.get('is_weekend', 0),
                'is_holiday': features.get('is_holiday', 0),
                'expected_attendees': features.get('expected_attendees', 50),
                'capacity_ratio': features.get('capacity_ratio', 0.5),
                'is_commercial': features.get('is_commercial', 0),
                'user_booking_count': user_booking_count,
            }
            
            # Add purpose keywords
            purpose_keywords = features.get('purpose_keywords', {})
            feature_dict.update(purpose_keywords)
            
            # Encode categorical features
            if 'user_id' in self.encoders:
                user_id_str = str(feature_dict['user_id'])
                if user_id_str in self.encoders['user_id'].classes_:
                    feature_dict['user_id_encoded'] = self.encoders['user_id'].transform([user_id_str])[0]
                else:
                    feature_dict['user_id_encoded'] = 0
            else:
                feature_dict['user_id_encoded'] = 0
            
            if 'facility_id' in self.encoders:
                facility_id_str = str(feature_dict['facility_id'])
                if facility_id_str in self.encoders['facility_id'].classes_:
                    feature_dict['facility_id_encoded'] = self.encoders['facility_id'].transform([facility_id_str])[0]
                else:
                    feature_dict['facility_id_encoded'] = 0
            else:
                feature_dict['facility_id_encoded'] = 0
            
            # Remove original categorical columns
            feature_dict.pop('user_id', None)
            feature_dict.pop('facility_id', None)
            
            # Convert to DataFrame
            feature_df = pd.DataFrame([feature_dict])
            
            # Ensure all columns from training are present
            # (This should match the training feature order)
            expected_features = [
                'capacity', 'amenities_count', 'start_hour', 'end_hour', 'duration_hours',
                'day_of_week', 'month', 'is_weekend', 'is_holiday', 'expected_attendees',
                'capacity_ratio', 'is_commercial', 'user_booking_count',
                'meeting', 'celebration', 'sports', 'education', 'religious',
                'community', 'feeding', 'commercial',
                'user_id_encoded', 'facility_id_encoded'
            ]
            
            # Add missing columns with default values
            for col in expected_features:
                if col not in feature_df.columns:
                    feature_df[col] = 0
            
            # Reorder columns to match training
            feature_df = feature_df[expected_features]
            
            # Predict
            score = self.model.predict(feature_df)[0]
            return float(score)
        
        except Exception as e:
            print(f"Error predicting relevance: {e}")
            return 0.0
    
    def recommend_facilities(self, facilities: list, user_id: int, purpose: str,
                           expected_attendees: int, time_slot: str, reservation_date: str,
                           is_commercial: bool = False, user_booking_count: int = 0,
                           limit: int = 5):
        """
        Get facility recommendations with ML-based relevance scores
        
        Args:
            facilities: List of facility dictionaries with id, capacity, amenities
            user_id: User ID
            purpose: Event purpose/description
            expected_attendees: Expected number of attendees
            time_slot: Time slot string (e.g., "08:00 - 12:00")
            reservation_date: Reservation date (YYYY-MM-DD)
            is_commercial: Whether reservation is commercial
            user_booking_count: User's total booking count
            limit: Number of recommendations to return
        
        Returns:
            List of facilities with ML relevance scores, sorted by score
        """
        if not self.loaded:
            if not self.load_model():
                # Fallback: return facilities without ML scores
                return facilities[:limit]
        
        # Parse reservation date
        try:
            res_date = pd.to_datetime(reservation_date)
            day_of_week = res_date.dayofweek
            month = res_date.month
            is_weekend = 1 if day_of_week >= 5 else 0
            holiday = is_holiday(res_date)
        except:
            day_of_week = 0
            month = 1
            is_weekend = 0
            holiday = 0
        
        # Extract time features
        time_feat = extract_time_features(time_slot)
        
        # Extract purpose keywords
        purpose_keywords = extract_purpose_keywords(purpose)
        
        recommendations = []
        
        for facility in facilities:
            # Prepare features
            capacity = extract_capacity_number(facility.get('capacity', 100))
            amenities = facility.get('amenities', '')
            amenities_count = len(str(amenities).split(',')) if amenities else 0
            capacity_ratio = expected_attendees / capacity if capacity > 0 else 0.5
            
            features = {
                'user_id': user_id,
                'facility_id': facility.get('id', 0),
                'capacity': capacity,
                'amenities_count': amenities_count,
                'start_hour': time_feat['start_hour'],
                'end_hour': time_feat['end_hour'],
                'duration_hours': time_feat['duration_hours'],
                'day_of_week': day_of_week,
                'month': month,
                'is_weekend': is_weekend,
                'is_holiday': holiday,
                'expected_attendees': expected_attendees,
                'capacity_ratio': capacity_ratio,
                'is_commercial': 1 if is_commercial else 0,
                'purpose_keywords': purpose_keywords,
            }
            
            # Get ML relevance score
            ml_score = self.predict_relevance(features, user_booking_count)
            
            # Combine with facility info
            recommendation = {
                **facility,
                'ml_relevance_score': ml_score,
            }
            
            recommendations.append(recommendation)
        
        # Sort by ML relevance score (descending)
        recommendations.sort(key=lambda x: x.get('ml_relevance_score', 0), reverse=True)
        
        return recommendations[:limit]


# Global model instance
_model_instance = None


def get_recommendation_model():
    """Get or create global model instance"""
    global _model_instance
    if _model_instance is None:
        _model_instance = FacilityRecommendationModel()
    return _model_instance
