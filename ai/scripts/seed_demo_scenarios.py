"""
Seed curated demo scenarios for AI capstone presentations.

Creates demo residents, booking history, violations, and peak-demand clusters
so Smart Scheduler, risk scoring, and demand forecasting have realistic inputs.
"""

import argparse
import json
import sys
from datetime import datetime, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent.parent))

from src.data_loader import DataLoader  # noqa: E402

# Demo password for all demo accounts (change after capstone if deployed)
DEMO_PASSWORD = "Demo2026!"


def demo_password_hash() -> str:
    """Return bcrypt hash compatible with PHP password_verify."""
    try:
        import bcrypt

        return bcrypt.hashpw(DEMO_PASSWORD.encode(), bcrypt.gensalt()).decode()
    except ImportError:
        pass
    # Fallback: run PHP if available
    import subprocess

    try:
        out = subprocess.check_output(
            ["php", "-r", f"echo password_hash('{DEMO_PASSWORD}', PASSWORD_DEFAULT);"],
            text=True,
            timeout=10,
        ).strip()
        if out.startswith("$2"):
            return out
    except (subprocess.SubprocessError, FileNotFoundError, OSError):
        pass
    raise RuntimeError(
        "Cannot hash demo password. Install bcrypt (`pip install bcrypt`) or ensure PHP is on PATH."
    )


DEMO_USERS = [
    {
        "email": "demo.low@culiat.test",
        "name": "Demo Resident (Low Risk)",
        "is_verified": 1,
    },
    {
        "email": "demo.high@culiat.test",
        "name": "Demo Resident (High Risk)",
        "is_verified": 0,
    },
    {
        "email": "demo.scheduler@culiat.test",
        "name": "Demo Scheduler User",
        "is_verified": 1,
    },
]

def next_weekday(weekday: int, min_days: int = 7) -> datetime:
    """weekday: Monday=0 … Sunday=6 (Python weekday)."""
    d = datetime.now().date() + timedelta(days=max(1, min_days))
    while d.weekday() != weekday:
        d += timedelta(days=1)
    return datetime.combine(d, datetime.min.time())


def upsert_demo_user(cursor, user: dict, password_hash: str) -> int:
    cursor.execute("SELECT id FROM users WHERE email = %s LIMIT 1", (user["email"],))
    row = cursor.fetchone()
    if row:
        uid = row["id"]
        cursor.execute(
            """
            UPDATE users
            SET name = %s, role = 'Resident', status = 'active',
                is_verified = %s, updated_at = CURRENT_TIMESTAMP
            WHERE id = %s
            """,
            (user["name"], user["is_verified"], uid),
        )
        return uid

    cursor.execute(
        """
        INSERT INTO users (name, email, password_hash, role, status, is_verified)
        VALUES (%s, %s, %s, 'Resident', 'active', %s)
        """,
        (user["name"], user["email"], password_hash, user["is_verified"]),
    )
    return cursor.lastrowid


def get_facility_ids(cursor) -> list[int]:
    cursor.execute("SELECT id FROM facilities WHERE status = 'available' ORDER BY id")
    rows = cursor.fetchall()
    return [r["id"] for r in rows]


def insert_reservation(cursor, data: dict) -> bool:
    try:
        cursor.execute(
            """
            INSERT INTO reservations (
                user_id, facility_id, reservation_date, time_slot, purpose,
                status, expected_attendees, is_commercial, auto_approved,
                priority_level, created_at
            ) VALUES (
                %(user_id)s, %(facility_id)s, %(reservation_date)s, %(time_slot)s, %(purpose)s,
                %(status)s, %(expected_attendees)s, %(is_commercial)s, %(auto_approved)s,
                %(priority_level)s, %(created_at)s
            )
            """,
            data,
        )
        return True
    except Exception as exc:
        print(f"  Warning: reservation insert failed: {exc}")
        return False


def seed_demo_scenarios(reset_violations: bool = False) -> None:
    print("=" * 60)
    print("Seed AI Demo Scenarios")
    print("=" * 60)

    loader = DataLoader()
    loader.connect()
    cursor = loader.connection.cursor()

    try:
        facility_ids = get_facility_ids(cursor)
        if not facility_ids:
            print("[ERROR] No available facilities. Create facilities first.")
            return

        print("\n1. Upserting demo users…")
        password_hash = demo_password_hash()
        user_ids = {}
        for u in DEMO_USERS:
            uid = upsert_demo_user(cursor, u, password_hash)
            user_ids[u["email"]] = uid
            print(f"   OK  {u['email']} (id={uid})")

        low_id = user_ids["demo.low@culiat.test"]
        high_id = user_ids["demo.high@culiat.test"]
        sched_id = user_ids["demo.scheduler@culiat.test"]

        print("\n2. Seeding scheduler user history (Smart Scheduler)…")
        today = datetime.now().date()
        history_count = 0
        for i in range(8):
            past = today - timedelta(days=14 + i * 7)
            ok = insert_reservation(
                cursor,
                {
                    "user_id": sched_id,
                    "facility_id": facility_ids[i % len(facility_ids)],
                    "reservation_date": past.strftime("%Y-%m-%d"),
                    "time_slot": "09:00 - 12:00",
                    "purpose": "Weekly community zumba class",
                    "status": "approved",
                    "expected_attendees": 35,
                    "is_commercial": 0,
                    "auto_approved": 1,
                    "priority_level": 2,
                    "created_at": (past - timedelta(days=3)).strftime("%Y-%m-%d %H:%M:%S"),
                },
            )
            history_count += int(ok)
        print(f"   OK  {history_count} past approved bookings for scheduler user")

        print("\n3. Seeding violations for high-risk demo user…")
        if reset_violations:
            cursor.execute("DELETE FROM user_violations WHERE user_id = %s", (high_id,))
        cursor.execute(
            "SELECT COUNT(*) AS c FROM user_violations WHERE user_id = %s",
            (high_id,),
        )
        existing_v = cursor.fetchone()["c"]
        if existing_v == 0:
            violations = [
                ("no_show", "Did not attend approved basketball rental.", "high"),
                ("late_cancellation", "Cancelled commercial event less than 24h before start.", "medium"),
                ("policy_violation", "Exceeded capacity limit during private function.", "high"),
            ]
            for vtype, desc, sev in violations:
                cursor.execute(
                    """
                    INSERT INTO user_violations
                        (user_id, violation_type, description, severity)
                    VALUES (%s, %s, %s, %s)
                    """,
                    (high_id, vtype, desc, sev),
                )
            print(f"   OK  {len(violations)} violations for demo.high@culiat.test")
        else:
            print(f"   OK  Skipped ({existing_v} violations already exist)")

        print("\n4. Seeding peak-demand cluster (demand forecasting demo)…")
        peak_facility = facility_ids[0]
        peak_date = next_weekday(5, 21)  # Saturday ~3 weeks out
        peak_date_str = peak_date.strftime("%Y-%m-%d")
        peak_count = 0
        for i in range(12):
            ok = insert_reservation(
                cursor,
                {
                    "user_id": low_id if i % 2 == 0 else sched_id,
                    "facility_id": peak_facility,
                    "reservation_date": peak_date_str,
                    "time_slot": f"{8 + (i % 4) * 2:02d}:00 - {10 + (i % 4) * 2:02d}:00",
                    "purpose": f"Peak demand demo booking #{i + 1}",
                    "status": "approved" if i < 9 else "pending",
                    "expected_attendees": 20 + i * 5,
                    "is_commercial": 0,
                    "auto_approved": i < 6,
                    "priority_level": 2,
                    "created_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                },
            )
            peak_count += int(ok)
        print(f"   OK  {peak_count} reservations on facility {peak_facility}, date {peak_date_str}")

        print("\n5. Writing scenario metadata…")
        meta_path = Path(__file__).parent.parent / "data" / "demo_scenarios.json"
        meta_path.parent.mkdir(exist_ok=True)
        meta = {
            "generated_at": datetime.now().isoformat(),
            "demo_users": {
                "low_risk": {"email": "demo.low@culiat.test", "password": DEMO_PASSWORD},
                "high_risk": {"email": "demo.high@culiat.test", "password": DEMO_PASSWORD},
                "scheduler": {"email": "demo.scheduler@culiat.test", "password": DEMO_PASSWORD},
            },
            "peak_demand": {
                "facility_id": peak_facility,
                "reservation_date": peak_date_str,
            },
            "booking_scenarios": ["low", "medium", "high", "unclear", "demand"],
        }
        meta_path.write_text(json.dumps(meta, indent=2), encoding="utf-8")
        print(f"   OK  {meta_path}")

        loader.connection.commit()
        print("\n[OK] Demo scenarios seeded successfully.")
        print("\nDemo login (password for all): Demo2026!")
        print("  demo.low@culiat.test   — verified, clean history")
        print("  demo.high@culiat.test  — unverified + violations")
        print("  demo.scheduler@culiat.test — repeat booking history")

    except Exception as exc:
        loader.connection.rollback()
        print(f"\n[ERROR] {exc}")
        raise
    finally:
        loader.disconnect()


def main() -> None:
    parser = argparse.ArgumentParser(description="Seed curated AI demo scenarios")
    parser.add_argument(
        "--yes", "-y", action="store_true", help="Skip confirmation prompt"
    )
    parser.add_argument(
        "--reset-violations",
        action="store_true",
        help="Clear and re-seed violations for high-risk demo user",
    )
    args = parser.parse_args()

    if not args.yes:
        print("\n[WARNING] This will upsert demo users and insert demo reservations/violations.")
        if input("Continue? (yes/no): ").lower() not in ("yes", "y"):
            print("Cancelled.")
            return

    seed_demo_scenarios(reset_violations=args.reset_violations)


if __name__ == "__main__":
    main()
