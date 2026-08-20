"""
Seed Sample Reservations
Generates realistic sample reservations for testing and model training.

Unlike a pure-random generator, every row here is drawn with structure that
mirrors real usage, so a model trained on it has an actual signal to learn
and its predictions have an explainable story:
  - Facility <-> purpose affinity (a covered court skews sports, a hall
    skews assembly/celebration - same category taxonomy already used in
    config/ai_helpers.php's matchPurpose()/frs_event_category_keyword_map()
    so PHP-side and ML-side "categories" agree).
  - Weekend/holiday demand skew (matches the PH holiday list already used
    in config/ai_helpers.php + services/PredictionService.php).
  - Category-appropriate time slots (fitness/sports lean early-morning or
    evening, assembly/community lean daytime/evening - same peak-hour lists
    already used in getSuggestedTimesForPurpose()).
  - Attendee count as a capacity ratio typical of the category, not a
    uniform draw across the whole capacity range.
  - Approval outcome correlated with a real risk proxy (over-capacity
    ratio, commercial flag, short booking notice) instead of independent
    of every other field.
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


# Category taxonomy mirrors frs_event_category_keyword_map() in
# config/ai_helpers.php - keep these two lists in sync if either changes.
CATEGORY_PURPOSES = {
    'sports': [
        'Basketball Tournament',
        'Volleyball Game',
        'Sports Festival',
        'Basketball Practice',
        'Badminton Tournament',
        'Community Sports Day',
        'Youth Sports Program',
        'Zumba Fitness Class',
        'Weekly Community Zumba Class',
        'Aerobics Workout Session',
    ],
    'assembly': [
        'Barangay General Assembly',
        'Community Meeting',
        'Town Hall Meeting',
        'Barangay Council Meeting',
        'Community Health Seminar',
        'Public Consultation',
        'Training Workshop',
        'Skills Development Class',
        'Computer Literacy Program',
        'Adult Education Class',
    ],
    'celebration': [
        'Wedding Reception',
        'Birthday Party',
        'Anniversary Celebration',
        'Fiesta Celebration',
        'Christmas Party',
        'Community Festival',
        'Graduation Ceremony',
    ],
    'cultural': [
        'Cultural Show',
        'Talent Performance Night',
        'Community Concert',
        'Dance Presentation',
        'Religious Retreat',
        'Church Service',
        'Prayer Meeting',
        'Bible Study',
        'Mass Celebration',
    ],
    'other': [
        'Family Gathering',
        'Personal Event',
        'Private Meeting',
        'Small Group Meeting',
        'Private Function',
        'Community Cleanup Program',
    ],
}

# Facility-name/description keywords that indicate a category affinity -
# same facility_keywords already used in frs_event_category_keyword_map().
FACILITY_CATEGORY_KEYWORDS = {
    'sports': ['court', 'sport', 'athletic', 'gym', 'fitness', 'basketball', 'volleyball', 'badminton', 'covered court', 'sports complex'],
    'assembly': ['hall', 'convention', 'function hall', 'meeting', 'room', 'center', 'centre'],
    'celebration': ['hall', 'convention', 'amphitheater', 'amphitheatre', 'park', 'open space'],
    'cultural': ['amphitheater', 'amphitheatre', 'theater', 'theatre', 'stage', 'church', 'chapel'],
}

# Category -> preferred time-slot start hours (mirrors the peak/offpeak
# lists already used in getSuggestedTimesForPurpose()/PredictionService).
CATEGORY_PEAK_START_HOURS = {
    'sports': [6, 7, 17, 18, 19],       # early morning or evening fitness/sports
    'assembly': [9, 10, 13, 14, 18],    # daytime meetings, some evening
    'celebration': [11, 14, 17, 18],    # midday to evening events
    'cultural': [10, 16, 17, 18],       # afternoon/evening programs
    'other': [8, 9, 13, 14, 15],
}

# Category -> typical attendance as a fraction of facility capacity
# (private/small gatherings don't fill a hall; sports/celebration events do).
CATEGORY_FILL_RATIO = {
    'sports': (0.5, 0.95),
    'assembly': (0.3, 0.7),
    'celebration': (0.5, 1.0),
    'cultural': (0.4, 0.85),
    'other': (0.15, 0.4),
}

CATEGORIES = list(CATEGORY_PURPOSES.keys())

# PH + Barangay Culiat holiday calendar - mirrors config/ai_helpers.php's
# calculateConflictRiskSimple() so "holiday demand skew" here lines up with
# the same dates the live risk/demand scoring already treats as special.
def _holiday_set(year: int) -> set:
    d = {
        f"{year}-01-01", f"{year}-02-25", f"{year}-04-09", f"{year}-06-12",
        f"{year}-08-21", f"{year}-11-01", f"{year}-11-02", f"{year}-11-30",
        f"{year}-12-25", f"{year}-12-30", f"{year}-09-08", f"{year}-02-11",
    }
    return d


def classify_facility(name: str, description: str) -> list:
    """Infer a facility's category affinities from its name/description
    text. Returns ALL categories tied at the top score, not just one - a
    "Multipurpose Hall" genuinely matches both 'assembly' and 'celebration'
    keyword lists (both include 'hall'), and real multipurpose halls really
    do host both meetings and wedding receptions. Picking a single
    "canonical" category here would just be a tie-break artifact of
    dict/list iteration order, not a real distinction - so a facility with
    a tie keeps every tied category as a valid affinity and pick_category()
    samples among them."""
    text = f"{name or ''} {description or ''}".lower()
    scores = {cat: 0 for cat in FACILITY_CATEGORY_KEYWORDS}
    for cat, keywords in FACILITY_CATEGORY_KEYWORDS.items():
        for kw in keywords:
            if kw in text:
                scores[cat] += 1
    best_score = max(scores.values())
    if best_score == 0:
        return ['other']
    return [cat for cat, s in scores.items() if s == best_score]


def pick_category(facility_categories: list) -> str:
    """75% of bookings match one of the facility's inferred affinities
    (sampled per-row when there's a tie, e.g. a hall alternating between
    assembly and celebration bookings); 25% are genuinely off-type (real
    venues do host the occasional out-of-type event) so the signal is
    strong but not deterministic."""
    if random.random() < 0.75:
        return random.choice(facility_categories)
    return random.choice(CATEGORIES)


def generate_time_slot(category: str) -> str:
    """Time slot biased toward the category's typical hours, with some
    spread so it isn't a fixed lookup table."""
    preferred = CATEGORY_PEAK_START_HOURS.get(category, CATEGORY_PEAK_START_HOURS['other'])
    if random.random() < 0.7:
        start_hour = random.choice(preferred)
    else:
        start_hour = random.randint(8, 19)
    start_minute = random.choice([0, 30])

    duration_hours = random.choice([2, 3, 4, 5, 6, 7, 8])
    end_hour = start_hour + duration_hours
    end_minute = start_minute

    if end_hour > 22:
        end_hour = 22
        end_minute = 0
    elif end_hour == 22 and end_minute > 0:
        end_minute = 0

    return f"{start_hour:02d}:{start_minute:02d} - {end_hour:02d}:{end_minute:02d}"


def pick_reservation_date(today) -> "datetime.date":
    """~40% of generated dates land on a weekend or PH holiday (real venues
    see weekend/holiday demand spikes) - the remainder spread uniformly
    across the -90..+60 day window like before."""
    for _ in range(20):
        days_offset = random.randint(-90, 60)
        candidate = today + timedelta(days=days_offset)
        is_weekend = candidate.weekday() >= 5
        is_holiday = candidate.strftime('%Y-%m-%d') in _holiday_set(candidate.year)
        if random.random() < 0.4:
            if is_weekend or is_holiday:
                return candidate
            continue
        return candidate
    # Fallback if 20 attempts never landed a weekend/holiday pick
    return today + timedelta(days=random.randint(-90, 60))


def generate_sample_reservations(count: int = 50, approved_ratio: float = 0.6):
    """
    Generate sample reservations with realistic, correlated data.

    Args:
        count: Number of reservations to generate
        approved_ratio: Baseline approval probability before the risk-proxy
            adjustment below (for training models)
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
            print("[ERROR] No active users found. Please create at least one user account first.")
            return

        # Get existing facilities (with name/description for category affinity)
        cursor.execute("SELECT id, capacity, name, description FROM facilities WHERE status = 'available'")
        facilities = cursor.fetchall()
        if not facilities:
            print("[ERROR] No available facilities found. Please create facilities first.")
            return

        facility_ids = [f['id'] for f in facilities]
        # Each facility maps to a LIST of tied top-scoring categories, not a
        # single one - see classify_facility()'s docstring for why.
        facility_categories = {f['id']: classify_facility(f.get('name', ''), f.get('description', '')) for f in facilities}

        cat_counts = {}
        for cats in facility_categories.values():
            for cat in cats:
                cat_counts[cat] = cat_counts.get(cat, 0) + 1
        print(f"\nFound {len(user_ids)} users and {len(facility_ids)} facilities")
        print("Facility category affinity (ties count toward every tied category): "
              + ", ".join(f"{c}={n}" for c, n in cat_counts.items()))
        print(f"Generating {count} sample reservations...")
        print(f"Baseline approved ratio: {approved_ratio * 100:.0f}% (adjusted per-row by risk proxy)")

        # Generate reservations
        reservations = []
        today = datetime.now().date()

        for i in range(count):
            reservation_date = pick_reservation_date(today)

            # Random user and facility
            user_id = random.choice(user_ids)
            facility_id = random.choice(facility_ids)

            facility = next(f for f in facilities if f['id'] == facility_id)
            facility_capacity_raw = facility['capacity']
            capacity_num = int(''.join(filter(str.isdigit, str(facility_capacity_raw)))) if facility_capacity_raw else 100
            capacity_num = max(capacity_num, 10)

            # Category driven by the facility's inferred affinity, not
            # independent of it
            category = pick_category(facility_categories[facility_id])
            purpose = random.choice(CATEGORY_PURPOSES[category])
            if random.random() < 0.3:
                purpose += f" - {random.choice(['Annual', 'Monthly', 'Weekly', 'Special', 'Regular'])}"

            time_slot = generate_time_slot(category)

            # Attendance as a category-typical fraction of capacity, not a
            # uniform draw across the whole range
            lo, hi = CATEGORY_FILL_RATIO.get(category, (0.2, 0.6))
            fill_ratio = random.uniform(lo, hi)
            expected_attendees = max(5, min(capacity_num, round(capacity_num * fill_ratio)))

            # Random commercial flag
            is_commercial = random.random() < 0.2  # 20% commercial

            # Random priority (1=LGU, 2=Community, 3=Private)
            if category == 'assembly' or 'barangay' in purpose.lower() or 'community' in purpose.lower():
                priority_level = random.choice([1, 2])
            else:
                priority_level = random.choice([2, 3])

            # Created at (spread over time, before the reservation date)
            created_at = reservation_date - timedelta(days=random.randint(0, 30))
            if created_at > today:
                created_at = reservation_date - timedelta(days=random.randint(1, 7))
            advance_days = (reservation_date - created_at).days

            # Risk proxy: real reasons a request would be scrutinized more -
            # booked over its "typical" fill ratio, commercial use, or very
            # short notice. Higher proxy -> lower approval odds, so the
            # auto-approval-risk model has an actual correlated signal to
            # learn instead of a status independent of every other field.
            over_capacity_ratio = expected_attendees / capacity_num
            risk_proxy = 0.0
            if over_capacity_ratio > 0.85:
                risk_proxy += 0.25
            if is_commercial:
                risk_proxy += 0.2
            if advance_days < 3:
                risk_proxy += 0.15

            approve_prob = max(0.05, min(0.97, approved_ratio - risk_proxy))
            roll = random.random()
            if roll < approve_prob:
                status = 'approved'
            elif roll < approve_prob + (1 - approve_prob) * 0.6:
                status = 'pending'
            else:
                status = 'denied'

            auto_approved = status == 'approved' and risk_proxy < 0.15 and random.random() < 0.6

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

        print(f"\n[OK] Successfully inserted {inserted_count} reservations!")
        print(f"\nStatus distribution:")
        for status, count in status_counts.items():
            print(f"  {status}: {count}")
        print(f"\nApproved reservations: {approved_count}")

        if approved_count >= 5:
            print("\n[OK] Enough approved reservations for Facility Recommendation model!")
        else:
            print(f"\n[WARN] Need {5 - approved_count} more approved reservations for Facility Recommendation model")

        total_count = sum(status_counts.values())
        if total_count >= 30:
            print("\n[OK] Enough reservations for Demand Forecasting model!")
        else:
            print(f"\n[WARN] Need {30 - total_count} more reservations for Demand Forecasting model")

        print("\nNext: train the models:")
        print("   python scripts/train_facility_recommendation.py")
        print("   python scripts/train_demand_forecasting.py")

    except Exception as e:
        print(f"\n[ERROR] {e}")
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
    parser.add_argument('--approved-ratio', type=float, default=0.6, help='Baseline approval ratio before risk-proxy adjustment (default: 0.6)')
    parser.add_argument('--yes', '-y', action='store_true', help='Skip confirmation prompt')

    args = parser.parse_args()

    if not args.yes:
        print(f"\n[WARNING] This will insert {args.count} sample reservations into your database.")
        response = input("Continue? (yes/no): ")
        if response.lower() not in ['yes', 'y']:
            print("Cancelled.")
            return

    generate_sample_reservations(count=args.count, approved_ratio=args.approved_ratio)


if __name__ == "__main__":
    main()
