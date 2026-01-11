"""
API endpoint for conflict detection prediction
Called from PHP to predict booking conflicts
"""

import sys
import json
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

from src.data_loader import DataLoader
import config
import joblib
import pandas as pd
from datetime import datetime
import re


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


def predict_conflict(facility_id, reservation_date, time_slot, expected_attendees, is_commercial, capacity):
    """Predict conflict probability for a reservation"""
    try:
        # Load model
        model_path = config.MODELS_DIR / 'conflict_detection.pkl'
        if not model_path.exists():
            return {'error': 'Model file not found', 'conflict_probability': 0.5}
        
        model = joblib.load(model_path)
        
        # Parse date
        res_date = pd.to_datetime(reservation_date)
        day_of_week = res_date.dayofweek
        month = res_date.month
        is_weekend = 1 if day_of_week >= 5 else 0
        holiday = is_holiday(res_date)
        
        # Extract time features
        time_feat = extract_time_features(time_slot)
        
        # Prepare features
        capacity_num = extract_capacity_number(capacity)
        expected_attendees_num = int(expected_attendees) if expected_attendees else 50
        capacity_ratio = expected_attendees_num / capacity_num if capacity_num > 0 else 0.5
        
        features = pd.DataFrame([{
            'facility_id': int(facility_id),
            'start_hour': time_feat['start_hour'],
            'end_hour': time_feat['end_hour'],
            'duration_hours': time_feat['duration_hours'],
            'day_of_week': day_of_week,
            'month': month,
            'is_weekend': is_weekend,
            'is_holiday': holiday,
            'capacity': capacity_num,
            'expected_attendees': expected_attendees_num,
            'capacity_ratio': capacity_ratio,
            'is_commercial': 1 if is_commercial else 0,
        }])
        
        # Predict
        if hasattr(model, 'predict_proba'):
            proba = model.predict_proba(features)[0]
            # Handle case where model only has one class (single column)
            if len(proba) == 1:
                conflict_proba = proba[0]  # Single class probability
            else:
                conflict_proba = proba[1]  # Conflict class probability (index 1)
        else:
            conflict_proba = 0.5
        
        return {
            'conflict_probability': float(conflict_proba),
            'is_conflict': bool(conflict_proba > 0.5),  # Convert to Python bool for JSON
            'confidence': float(abs(conflict_proba - 0.5) * 2),  # Normalize to 0-1
        }
    except Exception as e:
        return {'error': str(e), 'conflict_probability': 0.5}


if __name__ == "__main__":
    # Read input from command line arguments or stdin
    if len(sys.argv) > 1:
        # Command line arguments
        facility_id = sys.argv[1] if len(sys.argv) > 1 else None
        reservation_date = sys.argv[2] if len(sys.argv) > 2 else None
        time_slot = sys.argv[3] if len(sys.argv) > 3 else None
        expected_attendees = sys.argv[4] if len(sys.argv) > 4 else 50
        is_commercial = sys.argv[5] if len(sys.argv) > 5 else '0'
        capacity = sys.argv[6] if len(sys.argv) > 6 else '100'
    else:
        # Read from stdin (JSON)
        try:
            input_data = json.loads(sys.stdin.read())
            facility_id = input_data.get('facility_id')
            reservation_date = input_data.get('reservation_date')
            time_slot = input_data.get('time_slot')
            expected_attendees = input_data.get('expected_attendees', 50)
            is_commercial = input_data.get('is_commercial', False)
            capacity = input_data.get('capacity', '100')
        except:
            print(json.dumps({'error': 'Invalid input'}))
            sys.exit(1)
    
    if not all([facility_id, reservation_date, time_slot]):
        print(json.dumps({'error': 'Missing required parameters'}))
        sys.exit(1)
    
    result = predict_conflict(
        facility_id=int(facility_id),
        reservation_date=reservation_date,
        time_slot=time_slot,
        expected_attendees=int(expected_attendees),
        is_commercial=bool(int(is_commercial)) if isinstance(is_commercial, str) else is_commercial,
        capacity=capacity
    )
    
    print(json.dumps(result))
