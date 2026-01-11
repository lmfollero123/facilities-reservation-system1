"""
Script to extract data from database for training
Run this first to prepare training data
"""

import sys
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

from src.data_loader import DataLoader
import config
import pandas as pd
from datetime import datetime, timedelta

def main():
    """Extract and save training data"""
    print("=" * 60)
    print("Data Extraction Script")
    print("=" * 60)
    
    # Initialize data loader
    loader = DataLoader()
    
    try:
        loader.connect()
        
        # Extract last 12 months of data for training
        end_date = datetime.now().strftime('%Y-%m-%d')
        start_date = (datetime.now() - timedelta(days=365)).strftime('%Y-%m-%d')
        
        print(f"\nExtracting data from {start_date} to {end_date}...")
        
        # Load reservations
        print("\n1. Loading reservations...")
        reservations_df = loader.load_reservations(start_date=start_date, end_date=end_date)
        reservations_path = config.DATA_DIR / 'reservations.csv'
        reservations_df.to_csv(reservations_path, index=False)
        print(f"   Saved to: {reservations_path}")
        print(f"   Records: {len(reservations_df)}")
        
        # Load facilities
        print("\n2. Loading facilities...")
        facilities_df = loader.load_facilities()
        facilities_path = config.DATA_DIR / 'facilities.csv'
        facilities_df.to_csv(facilities_path, index=False)
        print(f"   Saved to: {facilities_path}")
        print(f"   Records: {len(facilities_df)}")
        
        # Load users
        print("\n3. Loading users...")
        users_df = loader.load_users()
        users_path = config.DATA_DIR / 'users.csv'
        users_df.to_csv(users_path, index=False)
        print(f"   Saved to: {users_path}")
        print(f"   Records: {len(users_df)}")
        
        # Load conflict data
        print("\n4. Loading historical conflicts...")
        conflicts_df = loader.get_historical_conflicts(lookback_months=12)
        conflicts_path = config.DATA_DIR / 'conflicts.csv'
        conflicts_df.to_csv(conflicts_path, index=False)
        print(f"   Saved to: {conflicts_path}")
        print(f"   Records: {len(conflicts_df)}")
        
        # Summary
        print("\n" + "=" * 60)
        print("Data Extraction Complete!")
        print("=" * 60)
        print(f"\nData saved to: {config.DATA_DIR}")
        print(f"\nSummary:")
        print(f"  - Reservations: {len(reservations_df)}")
        print(f"  - Facilities: {len(facilities_df)}")
        print(f"  - Users: {len(users_df)}")
        print(f"  - Conflicts: {len(conflicts_df)}")
        
    except Exception as e:
        print(f"\nError during data extraction: {e}")
        import traceback
        traceback.print_exc()
    finally:
        loader.disconnect()

if __name__ == '__main__':
    main()
