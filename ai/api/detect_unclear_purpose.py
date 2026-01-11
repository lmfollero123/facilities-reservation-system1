"""
API endpoint for unclear purpose detection
Called from PHP to detect unclear or suspicious purposes
"""

import sys
import json
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

from src.purpose_analysis import get_purpose_model


if __name__ == "__main__":
    # Read input from stdin (JSON)
    try:
        input_data = json.loads(sys.stdin.read())
        purpose = input_data.get('purpose')
    except:
        print(json.dumps({'error': 'Invalid input'}))
        sys.exit(1)
    
    if not purpose:
        print(json.dumps({'error': 'Missing purpose parameter'}))
        sys.exit(1)
    
    try:
        model = get_purpose_model()
        result = model.detect_unclear_purpose(str(purpose))
        
        # Ensure boolean is Python bool for JSON serialization
        result['is_unclear'] = bool(result['is_unclear'])
        result['probability'] = float(result['probability'])
        result['confidence'] = float(result['confidence'])
        
        print(json.dumps(result))
    except Exception as e:
        print(json.dumps({'error': str(e), 'is_unclear': True, 'probability': 0.5, 'confidence': 0.0}))
