"""
API endpoint for auto-approval risk assessment
Called from PHP to assess reservation risk
"""

import sys
import json
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

from src.auto_approval_risk import AutoApprovalRiskModel


if __name__ == "__main__":
    # Read input from command line arguments or stdin
    if len(sys.argv) > 1:
        # Command line arguments
        facility_id = sys.argv[1] if len(sys.argv) > 1 else None
        user_id = sys.argv[2] if len(sys.argv) > 2 else None
        reservation_date = sys.argv[3] if len(sys.argv) > 3 else None
        time_slot = sys.argv[4] if len(sys.argv) > 4 else None
        expected_attendees = sys.argv[5] if len(sys.argv) > 5 else 50
        is_commercial = sys.argv[6] if len(sys.argv) > 6 else '0'
        facility_auto_approve = sys.argv[7] if len(sys.argv) > 7 else '0'
        facility_capacity = sys.argv[8] if len(sys.argv) > 8 else '100'
        facility_max_duration_hours = sys.argv[9] if len(sys.argv) > 9 else '8.0'
        facility_capacity_threshold = sys.argv[10] if len(sys.argv) > 10 else '200'
        user_is_verified = sys.argv[11] if len(sys.argv) > 11 else '1'
        user_booking_count = sys.argv[12] if len(sys.argv) > 12 else '0'
        user_violation_count = sys.argv[13] if len(sys.argv) > 13 else '0'
    else:
        # Read from stdin (JSON)
        try:
            input_data = json.loads(sys.stdin.read())
            facility_id = input_data.get('facility_id')
            user_id = input_data.get('user_id')
            reservation_date = input_data.get('reservation_date')
            time_slot = input_data.get('time_slot')
            expected_attendees = input_data.get('expected_attendees', 50)
            is_commercial = input_data.get('is_commercial', False)
            facility_auto_approve = input_data.get('facility_auto_approve', False)
            facility_capacity = input_data.get('facility_capacity', '100')
            facility_max_duration_hours = input_data.get('facility_max_duration_hours', 8.0)
            facility_capacity_threshold = input_data.get('facility_capacity_threshold', 200)
            user_is_verified = input_data.get('user_is_verified', True)
            user_booking_count = input_data.get('user_booking_count', 0)
            user_violation_count = input_data.get('user_violation_count', 0)
        except:
            print(json.dumps({'error': 'Invalid input'}))
            sys.exit(1)
    
    if not all([facility_id, user_id, reservation_date, time_slot]):
        print(json.dumps({'error': 'Missing required parameters'}))
        sys.exit(1)
    
    try:
        model = AutoApprovalRiskModel()
        if not model.load_model():
            print(json.dumps({'error': 'Model not available', 'risk_level': 1, 'risk_probability': 0.5}))
            sys.exit(1)
        
        result = model.assess_reservation_risk(
            facility_id=int(facility_id),
            user_id=int(user_id),
            reservation_date=str(reservation_date),
            time_slot=str(time_slot),
            expected_attendees=int(expected_attendees),
            is_commercial=bool(int(is_commercial)) if isinstance(is_commercial, str) else is_commercial,
            facility_auto_approve=bool(int(facility_auto_approve)) if isinstance(facility_auto_approve, str) else facility_auto_approve,
            facility_capacity=facility_capacity,
            facility_max_duration_hours=float(facility_max_duration_hours),
            facility_capacity_threshold=int(facility_capacity_threshold) if facility_capacity_threshold != 'null' else None,
            user_is_verified=bool(int(user_is_verified)) if isinstance(user_is_verified, str) else user_is_verified,
            user_booking_count=int(user_booking_count),
            user_violation_count=int(user_violation_count),
        )
        
        print(json.dumps(result))
    except Exception as e:
        print(json.dumps({'error': str(e), 'risk_level': 1, 'risk_probability': 0.5}))
