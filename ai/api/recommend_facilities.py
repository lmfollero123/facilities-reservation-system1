"""
API endpoint for facility recommendations
Called from PHP to get ML-based facility recommendations
"""

import sys
import json
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

from src.facility_recommendation import get_recommendation_model


if __name__ == "__main__":
    # Read input from stdin (JSON)
    try:
        input_data = json.loads(sys.stdin.read())
        facilities = input_data.get('facilities', [])
        user_id = input_data.get('user_id')
        purpose = input_data.get('purpose', '')
        expected_attendees = input_data.get('expected_attendees', 50)
        time_slot = input_data.get('time_slot', '08:00 - 12:00')
        reservation_date = input_data.get('reservation_date')
        is_commercial = input_data.get('is_commercial', False)
        user_booking_count = input_data.get('user_booking_count', 0)
        limit = input_data.get('limit', 5)
    except Exception as e:
        print(json.dumps({'error': 'Invalid input: ' + str(e)}))
        sys.exit(1)
    
    if not facilities or not reservation_date:
        print(json.dumps({'error': 'Missing required parameters'}))
        sys.exit(1)
    
    try:
        model = get_recommendation_model()
        recommendations = model.recommend_facilities(
            facilities=facilities,
            user_id=int(user_id) if user_id else 0,
            purpose=str(purpose),
            expected_attendees=int(expected_attendees),
            time_slot=str(time_slot),
            reservation_date=str(reservation_date),
            is_commercial=bool(is_commercial),
            user_booking_count=int(user_booking_count),
            limit=int(limit)
        )
        
        # Convert numpy types to native Python types for JSON serialization
        result = []
        for rec in recommendations:
            rec_dict = {}
            for key, value in rec.items():
                if key == 'ml_relevance_score':
                    rec_dict[key] = float(value)
                elif isinstance(value, (int, float, str, bool, type(None))):
                    rec_dict[key] = value
                else:
                    rec_dict[key] = str(value)
            result.append(rec_dict)
        
        print(json.dumps({'recommendations': result}))
    except Exception as e:
        print(json.dumps({'error': str(e), 'recommendations': facilities[:limit]}))
