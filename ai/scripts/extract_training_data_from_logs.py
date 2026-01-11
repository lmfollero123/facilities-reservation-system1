"""
Extract Training Data from Audit Logs and Reservation History
Uses system logs and trails to improve AI model training
"""

import sys
from pathlib import Path
from datetime import datetime, timedelta

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

import pandas as pd
import json
from src.data_loader import DataLoader
import config


def extract_purposes_from_reservations(loader: DataLoader, start_date: str = None, end_date: str = None):
    """
    Extract purpose texts from reservations table
    This is the primary source for purpose analysis training
    """
    print("Extracting purposes from reservations...")
    
    query = """
        SELECT 
            id,
            purpose,
            status,
            reservation_date,
            created_at,
            facility_id,
            user_id
        FROM reservations
        WHERE purpose IS NOT NULL AND purpose != ''
    """
    
    params = []
    if start_date:
        query += " AND reservation_date >= %s"
        params.append(start_date)
    if end_date:
        query += " AND reservation_date <= %s"
        params.append(end_date)
    
    query += " ORDER BY created_at DESC"
    
    cursor = loader.connection.cursor()
    cursor.execute(query, params if params else None)
    columns = [desc[0] for desc in cursor.description]
    rows = cursor.fetchall()
    
    import datetime as dt
    data = []
    for row in rows:
        row_dict = dict(zip(columns, row))
        # Convert date objects to strings
        if 'reservation_date' in row_dict and isinstance(row_dict['reservation_date'], dt.date):
            row_dict['reservation_date'] = row_dict['reservation_date'].isoformat()
        if 'created_at' in row_dict and isinstance(row_dict['created_at'], dt.datetime):
            row_dict['created_at'] = row_dict['created_at'].isoformat()
        data.append(row_dict)
    
    df = pd.DataFrame(data)
    print(f"   Found {len(df)} reservations with purposes")
    return df


def extract_purposes_from_audit_logs(loader: DataLoader, start_date: str = None, end_date: str = None):
    """
    Extract purpose information from audit log details
    Audit logs may contain reservation details including purposes
    """
    print("Extracting purposes from audit logs...")
    
    query = """
        SELECT 
            id,
            action,
            module,
            details,
            created_at,
            user_id
        FROM audit_log
        WHERE module = 'Reservations'
          AND (action LIKE %s OR action LIKE %s)
          AND details IS NOT NULL
    """
    
    params = ['%reservation%', '%booking%']
    if start_date:
        query += " AND DATE(created_at) >= %s"
        params.append(start_date)
    if end_date:
        query += " AND DATE(created_at) <= %s"
        params.append(end_date)
    
    query += " ORDER BY created_at DESC"
    
    cursor = loader.connection.cursor()
    cursor.execute(query, params if params else None)
    columns = [desc[0] for desc in cursor.description]
    rows = cursor.fetchall()
    
    import datetime as dt
    data = []
    for row in rows:
        row_dict = dict(zip(columns, row))
        if 'created_at' in row_dict and isinstance(row_dict['created_at'], dt.datetime):
            row_dict['created_at'] = row_dict['created_at'].isoformat()
        data.append(row_dict)
    
    df = pd.DataFrame(data)
    print(f"   Found {len(df)} audit log entries")
    
    # Try to extract purpose from details field
    # Format: "RES-123 – Facility Name (date time_slot) [purpose info]"
    purposes = []
    for idx, row in df.iterrows():
        details = str(row['details']) if pd.notna(row['details']) else ''
        # Try to extract purpose if it's in the details
        # This is a heuristic - adjust based on your audit log format
        if 'purpose' in details.lower() or len(details) > 50:
            purposes.append({
                'source': 'audit_log',
                'text': details,
                'action': row['action'],
                'created_at': row['created_at']
            })
    
    return pd.DataFrame(purposes) if purposes else pd.DataFrame()


def extract_from_reservation_history(loader: DataLoader, start_date: str = None, end_date: str = None):
    """
    Extract information from reservation_history notes
    History notes may contain purpose-related information
    """
    print("Extracting data from reservation history...")
    
    query = """
        SELECT 
            rh.id,
            rh.reservation_id,
            rh.status,
            rh.note,
            rh.created_at,
            r.purpose,
            r.status as reservation_status
        FROM reservation_history rh
        JOIN reservations r ON rh.reservation_id = r.id
        WHERE rh.note IS NOT NULL AND rh.note != ''
    """
    
    params = []
    if start_date:
        query += " AND DATE(rh.created_at) >= %s"
        params.append(start_date)
    if end_date:
        query += " AND DATE(rh.created_at) <= %s"
        params.append(end_date)
    
    query += " ORDER BY rh.created_at DESC"
    
    cursor = loader.connection.cursor()
    cursor.execute(query, params if params else None)
    columns = [desc[0] for desc in cursor.description]
    rows = cursor.fetchall()
    
    import datetime as dt
    data = []
    for row in rows:
        row_dict = dict(zip(columns, row))
        if 'created_at' in row_dict and isinstance(row_dict['created_at'], dt.datetime):
            row_dict['created_at'] = row_dict['created_at'].isoformat()
        data.append(row_dict)
    
    df = pd.DataFrame(data)
    print(f"   Found {len(df)} reservation history entries")
    return df


def save_training_data(reservations_df: pd.DataFrame, output_path: Path):
    """
    Save extracted training data to CSV for model retraining
    """
    if reservations_df.empty:
        print("   No data to save")
        return
    
    # Prepare training data format
    training_data = reservations_df[['purpose', 'status']].copy()
    training_data = training_data.dropna(subset=['purpose'])
    training_data = training_data[training_data['purpose'].str.strip() != '']
    
    # Save to CSV
    output_path.parent.mkdir(parents=True, exist_ok=True)
    training_data.to_csv(output_path, index=False)
    print(f"   Saved {len(training_data)} training samples to {output_path}")
    
    # Also save full data with metadata
    full_output = output_path.parent / f"{output_path.stem}_full.csv"
    reservations_df.to_csv(full_output, index=False)
    print(f"   Saved full data with metadata to {full_output}")


def main():
    """Main extraction function"""
    print("=" * 60)
    print("Extract Training Data from Logs and Trails")
    print("=" * 60)
    
    loader = DataLoader()
    
    try:
        loader.connect()
        
        # Set date range (last year by default)
        end_date = datetime.now().strftime('%Y-%m-%d')
        start_date = (datetime.now() - timedelta(days=365)).strftime('%Y-%m-%d')
        
        print(f"\nDate range: {start_date} to {end_date}")
        
        # Extract from different sources
        print("\n1. Extracting from reservations table...")
        reservations_df = extract_purposes_from_reservations(loader, start_date, end_date)
        
        print("\n2. Extracting from audit logs...")
        audit_df = extract_purposes_from_audit_logs(loader, start_date, end_date)
        
        print("\n3. Extracting from reservation history...")
        history_df = extract_from_reservation_history(loader, start_date, end_date)
        
        # Combine and save
        print("\n4. Saving training data...")
        output_dir = config.DATA_DIR
        output_dir.mkdir(parents=True, exist_ok=True)
        
        # Save reservations data (primary source)
        if not reservations_df.empty:
            save_training_data(reservations_df, output_dir / 'training_purposes_from_reservations.csv')
        
        # Save audit log data (if any)
        if not audit_df.empty:
            audit_df.to_csv(output_dir / 'training_purposes_from_audit_logs.csv', index=False)
            print(f"   Saved {len(audit_df)} audit log entries")
        
        # Save history data (if any)
        if not history_df.empty:
            history_df.to_csv(output_dir / 'training_data_from_history.csv', index=False)
            print(f"   Saved {len(history_df)} history entries")
        
        # Create summary
        print("\n5. Summary:")
        print(f"   Total reservations with purposes: {len(reservations_df)}")
        print(f"   Audit log entries: {len(audit_df)}")
        print(f"   History entries: {len(history_df)}")
        
        # Status distribution
        if not reservations_df.empty and 'status' in reservations_df.columns:
            print("\n   Status distribution:")
            status_counts = reservations_df['status'].value_counts()
            for status, count in status_counts.items():
                print(f"      {status}: {count}")
        
        print("\n✅ Training data extraction complete!")
        print(f"\nTo retrain models with this data, run:")
        print(f"   python ai/scripts/train_purpose_analysis.py")
        
    except Exception as e:
        print(f"\n❌ Error during extraction: {e}")
        import traceback
        traceback.print_exc()
    finally:
        loader.disconnect()


if __name__ == "__main__":
    main()
