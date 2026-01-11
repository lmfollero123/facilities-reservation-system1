"""
API endpoint for purpose category classification
Called from PHP to categorize reservation purposes
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
        result = model.classify_purpose_category(str(purpose))
        
        print(json.dumps(result))
    except Exception as e:
        print(json.dumps({'error': str(e), 'category': 'private', 'confidence': 0.0}))
