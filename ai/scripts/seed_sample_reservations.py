"""
Seed Sample Reservations
Generates realistic sample reservations for testing and model training
"""

import sys
from pathlib import Path
from datetime import datetime, timedelta
import random

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

import pandas as pd
import pymysql
from src.data_loader import DataLoader
import config


# Sample purposes for different categories
SAMPLE_PURPOSES = {
    'community': [
        'Barangay General Assembly',
        'Community Meeting',
        'Town Hall Meeting',
        'Barangay Council Meeting',
        'Community Health Seminar',
        'Public Consultation',
        'Community Cleanup Program'
    ],
    'sports': [
        'Basketball Tournament',
        'Volleyball Game',
        'Sports Festival',
        'Basketball Practice',
        'Badminton Tournament',
        'Community Sports Day',
        'Youth Sports Program'
    ],
    'education': [
        'Zumba Fitness Class',
        'Yoga Workshop',
        'Educational Seminar',
        'Training Workshop',
        'Skills Development Class',
        'Computer Literacy Program',
        'Adult Education Class'
    ],
    'celebration': [
        'Wedding Reception',
        'Birthday Party',
        'Anniversary Celebration',
        'Fiesta Celebration',
        'Christmas Party',
        'Community Festival',
        'Graduation Ceremony'
    ],
    'religious': [
        'Church Service',
        'Prayer Meeting',
        'Bible Study',
        'Religious Seminar',
        'Mass Celebration',
        'Religious Retreat'
    ],
    'private': [
        'Family Gathering',
        'Personal Event',
        'Private Meeting',
        'Small Group Meeting',
        'Private Function'
    ]
}

def generate_time_slot():
    """
    Generate a random time slot in open format (HH:MM - HH:MM)
    Duration: 2-8 hours, starting between 8:00 and 18:00
    """
    # Start hour between 8 and 18 (8 AM to 6 PM)
    start_hour = random.randint(8, 18)
    start_minute = random.choice([0, 30])  # On the hour or half hour
    
    # Duration: 2-8 hours
    duration_hours = random.choice([2, 3, 4, 5, 6, 7, 8])
    
    # Calculate end time
    end_hour = start_hour + duration_hours
    end_minute = start_minute
    
    # Ensure end time doesn't go past 22:00 (10 PM)
    if end_hour > 22:
        end_hour = 22
        end_minute = 0
    elif end_hour == 22 and end_minute > 0:
        end_minute = 0
    
    return f"{start_hour:02d}:{start_minute:02d} - {end_hour:02d}:{end_minute:02d}"

STATUS_OPTIONS = ['approved', 'pending', 'approved', 'approved', 'denied']  # More approved for training


def generate_sample_reservations(count: int = 50, approved_ratio: float = 0.6):
    """
    Generate sample reservations with realistic data
    
    Args:
        count: Number of reservations to generate
        approved_ratio: Ratio of approved reservations (for training models)
    """
    print("=" * 60)
    print("Seed Sample Reservations")
    print("=" * 60)
    
    loader = DataLoader()
    
    try:
        loader.connect()
        cursor = loader.connection.cursor()
        
        # Get existing users
        cursor.execute("SELECT id FROM users WHERE status = 'active' LIMIT 20")
        user_rows = cursor.fetchall()
        user_ids = [row['id'] for row in user_rows]
        if not user_ids:
            print("❌ Error: No active users found. Please create at least one user account first.")
            return
        
        # Get existing facilities
        cursor.execute("SELECT id, capacity FROM facilities WHERE status = 'available'")
        facilities = cursor.fetchall()
        if not facilities:
            print("❌ Error: No available facilities found. Please create facilities first.")
            return
        
        facility_ids = [f['id'] for f in facilities]
        
        print(f"\nFound {len(user_ids)} users and {len(facility_ids)} facilities")
        print(f"Generating {count} sample reservations...")
        print(f"Approved ratio: {approved_ratio * 100:.0f}%")
        
        # Generate reservations
        reservations = []
        today = datetime.now().date()
        
        for i in range(count):
            # Random date between 90 days ago and 60 days in the future
            days_offset = random.randint(-90, 60)
            reservation_date = today + timedelta(days=days_offset)
            
            # Skip past dates that are too old (for realistic data)
            if reservation_date < today - timedelta(days=365):
                reservation_date = today - timedelta(days=random.randint(1, 90))
            
            # Random user and facility
            user_id = random.choice(user_ids)
            facility_id = random.choice(facility_ids)
            
            # Get facility capacity
            facility_capacity = next((f['capacity'] for f in facilities if f['id'] == facility_id), '100')
            capacity_num = int(''.join(filter(str.isdigit, str(facility_capacity)))) if facility_capacity else 100
            
            # Random purpose category and purpose
            category = random.choice(list(SAMPLE_PURPOSES.keys()))
            purpose = random.choice(SAMPLE_PURPOSES[category])
            
            # Add some variation to purposes
            if random.random() < 0.3:
                purpose += f" - {random.choice(['Annual', 'Monthly', 'Weekly', 'Special', 'Regular'])}"
            
            # Random time slot (open format)
            time_slot = generate_time_slot()
            
            # Random status (weighted towards approved for training)
            if random.random() < approved_ratio:
                status = 'approved'
            else:
                status = random.choice(['pending', 'denied'])
            
            # Random expected attendees (realistic based on capacity)
            max_attendees = min(capacity_num, 200)
            expected_attendees = random.randint(10, max_attendees) if max_attendees > 10 else random.randint(5, 50)
            
            # Random commercial flag
            is_commercial = random.random() < 0.2  # 20% commercial
            
            # Random priority (1=LGU, 2=Community, 3=Private)
            if 'barangay' in purpose.lower() or 'community' in purpose.lower() or category == 'community':
                priority_level = random.choice([1, 2])
            else:
                priority_level = random.choice([2, 3])
            
            # Auto-approved flag (only for approved reservations with certain conditions)
            auto_approved = status == 'approved' and random.random() < 0.4
            
            # Created at (spread over time)
            created_at = reservation_date - timedelta(days=random.randint(0, 30))
            if created_at > today:
                created_at = reservation_date - timedelta(days=random.randint(1, 7))
            
            reservations.append({
                'user_id': user_id,
                'facility_id': facility_id,
                'reservation_date': reservation_date.strftime('%Y-%m-%d'),
                'time_slot': time_slot,
                'purpose': purpose,
                'status': status,
                'expected_attendees': expected_attendees,
                'is_commercial': 1 if is_commercial else 0,
                'auto_approved': 1 if auto_approved else 0,
                'priority_level': priority_level,
                'created_at': created_at.strftime('%Y-%m-%d %H:%M:%S'),
            })
        
        # Insert reservations
        print(f"\nInserting {len(reservations)} reservations into database...")
        
        insert_query = """
            INSERT INTO reservations (
                user_id, facility_id, reservation_date, time_slot, purpose,
                status, expected_attendees, is_commercial, auto_approved,
                priority_level, created_at
            ) VALUES (
                %(user_id)s, %(facility_id)s, %(reservation_date)s, %(time_slot)s, %(purpose)s,
                %(status)s, %(expected_attendees)s, %(is_commercial)s, %(auto_approved)s,
                %(priority_level)s, %(created_at)s
            )
        """
        
        inserted_count = 0
        for reservation in reservations:
            try:
                cursor.execute(insert_query, reservation)
                inserted_count += 1
            except Exception as e:
                print(f"  Warning: Failed to insert reservation: {e}")
                continue
        
        loader.connection.commit()
        
        # Show statistics
        cursor.execute("SELECT status, COUNT(*) as count FROM reservations GROUP BY status")
        status_counts = {row['status']: row['count'] for row in cursor.fetchall()}
        
        cursor.execute("SELECT COUNT(*) as count FROM reservations WHERE status = 'approved'")
        approved_count = cursor.fetchone()['count']
        
        print(f"\n✅ Successfully inserted {inserted_count} reservations!")
        print(f"\nStatus distribution:")
        for status, count in status_counts.items():
            print(f"  {status}: {count}")
        print(f"\nApproved reservations: {approved_count}")
        
        if approved_count >= 5:
            print("\n✅ Enough approved reservations for Facility Recommendation model!")
        else:
            print(f"\n⚠️  Need {5 - approved_count} more approved reservations for Facility Recommendation model")
        
        total_count = sum(status_counts.values())
        if total_count >= 30:
            print("\n✅ Enough reservations for Demand Forecasting model!")
        else:
            print(f"\n⚠️  Need {30 - total_count} more reservations for Demand Forecasting model")
        
        print("\n💡 You can now train the models:")
        print("   python scripts/train_facility_recommendation.py")
        print("   python scripts/train_demand_forecasting.py")
        
    except Exception as e:
        print(f"\n❌ Error: {e}")
        import traceback
        traceback.print_exc()
        if loader.connection:
            loader.connection.rollback()
    finally:
        loader.disconnect()


def main():
    """Main function"""
    import argparse
    
    parser = argparse.ArgumentParser(description='Seed sample reservations for testing')
    parser.add_argument('--count', type=int, default=50, help='Number of reservations to generate (default: 50)')
    parser.add_argument('--approved-ratio', type=float, default=0.6, help='Ratio of approved reservations (default: 0.6)')
    
    args = parser.parse_args()
    
    # Safety check
    print(f"\n⚠️  WARNING: This will insert {args.count} sample reservations into your database.")
    response = input("Continue? (yes/no): ")
    
    if response.lower() not in ['yes', 'y']:
        print("Cancelled.")
        return
    
    generate_sample_reservations(count=args.count, approved_ratio=args.approved_ratio)


if __name__ == "__main__":
    main()
