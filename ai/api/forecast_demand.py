"""
API endpoint for demand forecasting
Called from PHP to predict future booking demand
"""

import sys
import json
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

from src.demand_forecasting import get_demand_model


if __name__ == "__main__":
    # Read input from stdin (JSON)
    try:
        input_data = json.loads(sys.stdin.read())
        facility_id = input_data.get('facility_id')
        date = input_data.get('date')
        historical_data = input_data.get('historical_data', None)
    except:
        print(json.dumps({'error': 'Invalid input'}))
        sys.exit(1)
    
    if not facility_id or not date:
        print(json.dumps({'error': 'Missing required parameters'}))
        sys.exit(1)
    
    try:
        model = get_demand_model()
        prediction = model.predict_demand(
            facility_id=int(facility_id),
            date=str(date),
            historical_data=historical_data
        )
        
        print(json.dumps(prediction))
    except Exception as e:
        print(json.dumps({'error': str(e), 'predicted_count': 0.0, 'confidence': 0.0}))
