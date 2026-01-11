"""
Demand Forecasting Model Inference
Loads trained model and predicts future booking demand
"""

import sys
from pathlib import Path
import pandas as pd
import numpy as np
import joblib
from datetime import datetime, timedelta

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

import config


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


class DemandForecastingModel:
    """Demand Forecasting Model for inference"""
    
    def __init__(self):
        self.model = None
        self.feature_cols = None
        self.loaded = False
    
    def load_model(self):
        """Load trained model"""
        try:
            model_path = config.MODELS_DIR / 'demand_forecasting_model.pkl'
            
            if not model_path.exists():
                raise FileNotFoundError(f"Model file not found: {model_path}")
            
            model_data = joblib.load(model_path)
            self.model = model_data['model']
            self.feature_cols = model_data['feature_cols']
            self.loaded = True
            return True
        except Exception as e:
            print(f"Error loading model: {e}")
            return False
    
    def predict_demand(self, facility_id: int, date: str, historical_data: list = None):
        """
        Predict booking demand for a facility on a given date
        
        Args:
            facility_id: Facility ID
            date: Date string (YYYY-MM-DD)
            historical_data: Optional list of historical booking counts for lag features
        
        Returns:
            Dictionary with predicted booking count and confidence
        """
        if not self.loaded:
            if not self.load_model():
                return {
                    'predicted_count': 0.0,
                    'confidence': 0.0
                }
        
        try:
            # Parse date
            date_obj = pd.to_datetime(date)
            
            # Prepare features
            features = {
                'facility_id': facility_id,
                'year': date_obj.year,
                'month': date_obj.month,
                'day': date_obj.day,
                'day_of_week': date_obj.dayofweek,
                'day_of_year': date_obj.timetuple().tm_yday,
                'week': date_obj.isocalendar()[1],
                'is_weekend': 1 if date_obj.dayofweek >= 5 else 0,
                'is_month_start': 1 if date_obj.day <= 7 else 0,
                'is_month_end': 1 if date_obj.day >= 23 else 0,
                'is_holiday': is_holiday(date_obj),
                'booking_count_lag1': 0.0,
                'booking_count_lag7': 0.0,
                'booking_count_lag30': 0.0,
                'booking_count_ma7': 0.0,
                'booking_count_ma30': 0.0,
            }
            
            # Use historical data for lag features if provided
            if historical_data and len(historical_data) > 0:
                historical_df = pd.DataFrame(historical_data)
                historical_df = historical_df.sort_values('date')
                historical_counts = historical_df['booking_count'].tolist()
                
                if len(historical_counts) >= 1:
                    features['booking_count_lag1'] = float(historical_counts[-1])
                if len(historical_counts) >= 7:
                    features['booking_count_lag7'] = float(historical_counts[-7])
                if len(historical_counts) >= 30:
                    features['booking_count_lag30'] = float(historical_counts[-30])
                if len(historical_counts) >= 7:
                    features['booking_count_ma7'] = float(np.mean(historical_counts[-7:]))
                if len(historical_counts) >= 30:
                    features['booking_count_ma30'] = float(np.mean(historical_counts[-30:]))
            
            # Create DataFrame with features
            feature_df = pd.DataFrame([features])
            
            # Ensure all columns are present
            for col in self.feature_cols:
                if col not in feature_df.columns:
                    feature_df[col] = 0
            
            # Reorder columns to match training
            feature_df = feature_df[self.feature_cols]
            
            # Predict
            predicted_count = self.model.predict(feature_df)[0]
            predicted_count = max(0.0, float(predicted_count))  # Ensure non-negative
            
            # Calculate confidence (simplified - could be improved with prediction intervals)
            confidence = 0.7  # Default confidence
            
            return {
                'predicted_count': predicted_count,
                'confidence': confidence
            }
        
        except Exception as e:
            print(f"Error predicting demand: {e}")
            return {
                'predicted_count': 0.0,
                'confidence': 0.0
            }
    
    def forecast_demand_range(self, facility_id: int, start_date: str, end_date: str, historical_data: list = None):
        """
        Forecast demand for a date range
        
        Args:
            facility_id: Facility ID
            start_date: Start date (YYYY-MM-DD)
            end_date: End date (YYYY-MM-DD)
            historical_data: Optional historical booking data
        
        Returns:
            List of predictions for each date in range
        """
        start = pd.to_datetime(start_date)
        end = pd.to_datetime(end_date)
        date_range = pd.date_range(start=start, end=end, freq='D')
        
        predictions = []
        for date in date_range:
            date_str = date.strftime('%Y-%m-%d')
            prediction = self.predict_demand(facility_id, date_str, historical_data)
            predictions.append({
                'date': date_str,
                'facility_id': facility_id,
                'predicted_count': prediction['predicted_count'],
                'confidence': prediction['confidence']
            })
        
        return predictions


# Global model instance
_model_instance = None


def get_demand_model():
    """Get or create global model instance"""
    global _model_instance
    if _model_instance is None:
        _model_instance = DemandForecastingModel()
    return _model_instance
