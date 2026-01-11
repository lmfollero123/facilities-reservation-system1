"""
Data loading utilities for training AI models
Connects to MySQL database and extracts reservation/facility data
"""

import pandas as pd
import pymysql
from datetime import datetime, timedelta
from typing import Dict, List, Optional, Tuple
import config


class DataLoader:
    """Load data from MySQL database for ML training"""
    
    def __init__(self, db_config: Dict = None):
        """
        Initialize data loader with database configuration
        
        Args:
            db_config: Database configuration dictionary
        """
        self.db_config = db_config or config.DB_CONFIG
        self.connection = None
    
    def connect(self):
        """Establish database connection"""
        try:
            self.connection = pymysql.connect(
                host=self.db_config['host'],
                port=self.db_config['port'],
                user=self.db_config['user'],
                password=self.db_config['password'],
                database=self.db_config['database'],
                charset=self.db_config['charset'],
                cursorclass=pymysql.cursors.DictCursor
            )
            print(f"Connected to database: {self.db_config['database']}")
        except Exception as e:
            print(f"Error connecting to database: {e}")
            raise
    
    def disconnect(self):
        """Close database connection"""
        if self.connection:
            self.connection.close()
            print("Database connection closed")
    
    def load_reservations(self, start_date: str = None, end_date: str = None) -> pd.DataFrame:
        """
        Load reservation data from database
        
        Args:
            start_date: Start date for filtering (YYYY-MM-DD format)
            end_date: End date for filtering (YYYY-MM-DD format)
            
        Returns:
            DataFrame with reservation data
        """
        if not self.connection:
            self.connect()
        
        query = """
            SELECT 
                r.id,
                r.user_id,
                r.facility_id,
                r.reservation_date,
                r.time_slot,
                r.purpose,
                r.status,
                r.expected_attendees,
                r.is_commercial,
                r.auto_approved,
                r.created_at,
                r.updated_at,
                f.name AS facility_name,
                f.capacity AS facility_capacity,
                f.amenities AS facility_amenities,
                f.status AS facility_status,
                u.name AS user_name,
                u.role AS user_role
            FROM reservations r
            JOIN facilities f ON r.facility_id = f.id
            JOIN users u ON r.user_id = u.id
            WHERE 1=1
        """
        
        params = []
        if start_date:
            query += " AND r.reservation_date >= %s"
            params.append(start_date)
        if end_date:
            query += " AND r.reservation_date <= %s"
            params.append(end_date)
        
        query += " ORDER BY r.reservation_date, r.created_at"
        
        try:
            # Use cursor to get data and convert dates properly
            # Connection was created with DictCursor, so fetchall returns dicts
            cursor = self.connection.cursor()
            cursor.execute(query, tuple(params) if params else None)
            rows = cursor.fetchall()
            
            # Convert to list of dicts and handle dates
            import datetime as dt
            data = []
            for row in rows:
                # row is already a dict because of DictCursor
                row_dict = dict(row)
                # Convert datetime.date objects to strings for pandas
                if 'reservation_date' in row_dict and isinstance(row_dict['reservation_date'], dt.date):
                    row_dict['reservation_date'] = row_dict['reservation_date'].isoformat()
                data.append(row_dict)
            
            df = pd.DataFrame(data)
            
            # Convert date strings to datetime
            if 'reservation_date' in df.columns:
                df['reservation_date'] = pd.to_datetime(df['reservation_date'], format='%Y-%m-%d', errors='coerce')
            if 'created_at' in df.columns:
                df['created_at'] = pd.to_datetime(df['created_at'], errors='coerce')
            if 'updated_at' in df.columns:
                df['updated_at'] = pd.to_datetime(df['updated_at'], errors='coerce')
            
            cursor.close()
            print(f"Loaded {len(df)} reservations")
            return df
        except Exception as e:
            print(f"Error loading reservations: {e}")
            raise
    
    def load_facilities(self) -> pd.DataFrame:
        """
        Load facility data from database
        
        Returns:
            DataFrame with facility data
        """
        if not self.connection:
            self.connect()
        
        query = """
            SELECT 
                id,
                name,
                description,
                capacity,
                amenities,
                location,
                latitude,
                longitude,
                status,
                auto_approve,
                capacity_threshold,
                max_duration_hours,
                created_at,
                updated_at
            FROM facilities
            ORDER BY id
        """
        
        try:
            df = pd.read_sql(query, self.connection)
            print(f"Loaded {len(df)} facilities")
            return df
        except Exception as e:
            print(f"Error loading facilities: {e}")
            raise
    
    def load_users(self) -> pd.DataFrame:
        """
        Load user data from database
        
        Returns:
            DataFrame with user data
        """
        if not self.connection:
            self.connect()
        
        query = """
            SELECT 
                id,
                name,
                email,
                role,
                status,
                latitude,
                longitude,
                created_at
            FROM users
            WHERE status = 'active'
            ORDER BY id
        """
        
        try:
            df = pd.read_sql(query, self.connection)
            print(f"Loaded {len(df)} users")
            return df
        except Exception as e:
            print(f"Error loading users: {e}")
            raise
    
    def get_historical_conflicts(self, lookback_months: int = 6) -> pd.DataFrame:
        """
        Load historical conflict data (overlapping reservations)
        
        Args:
            lookback_months: Number of months to look back
            
        Returns:
            DataFrame with conflict information
        """
        if not self.connection:
            self.connect()
        
        start_date = (datetime.now() - timedelta(days=lookback_months * 30)).strftime('%Y-%m-%d')
        
        # This query finds overlapping reservations (conflicts)
        query = """
            SELECT 
                r1.id AS reservation1_id,
                r1.facility_id,
                r1.reservation_date,
                r1.time_slot AS time_slot1,
                r1.status AS status1,
                r1.user_id AS user1_id,
                r2.id AS reservation2_id,
                r2.time_slot AS time_slot2,
                r2.status AS status2,
                r2.user_id AS user2_id,
                CASE 
                    WHEN r1.status = 'approved' AND r2.status = 'approved' THEN 1
                    ELSE 0
                END AS is_conflict
            FROM reservations r1
            JOIN reservations r2 ON r1.facility_id = r2.facility_id 
                AND r1.reservation_date = r2.reservation_date
                AND r1.id < r2.id
            WHERE r1.reservation_date >= %s
                AND r1.status IN ('approved', 'pending')
                AND r2.status IN ('approved', 'pending')
            ORDER BY r1.reservation_date, r1.facility_id
        """
        
        try:
            df = pd.read_sql(query, self.connection, params=[start_date])
            print(f"Loaded {len(df)} potential conflicts")
            return df
        except Exception as e:
            print(f"Error loading conflicts: {e}")
            raise
    
    def __enter__(self):
        """Context manager entry"""
        self.connect()
        return self
    
    def __exit__(self, exc_type, exc_val, exc_tb):
        """Context manager exit"""
        self.disconnect()
