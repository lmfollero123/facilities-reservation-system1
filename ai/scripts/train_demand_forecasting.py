"""
Train Demand Forecasting Model
Predicts future booking demand for facilities using time series forecasting
"""

import sys
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestRegressor
from sklearn.metrics import mean_squared_error, mean_absolute_error, r2_score
import joblib
from datetime import datetime, timedelta
import warnings
warnings.filterwarnings('ignore')

from src.data_loader import DataLoader
import config

# Try to import Prophet for better time series forecasting (optional)
try:
    from prophet import Prophet
    PROPHET_AVAILABLE = True
except ImportError:
    PROPHET_AVAILABLE = False
    print("Prophet not available. Using Random Forest for forecasting.")
    print("Install with: pip install prophet")


def prepare_time_series_data(reservations_df: pd.DataFrame, facilities_df: pd.DataFrame):
    """
    Prepare time series data for demand forecasting
    
    Returns:
        DataFrame with columns: date, facility_id, booking_count, and features
    """
    # Ensure reservation_date is datetime
    if 'reservation_date' in reservations_df.columns:
        if not pd.api.types.is_datetime64_any_dtype(reservations_df['reservation_date']):
            reservations_df['reservation_date'] = pd.to_datetime(reservations_df['reservation_date'], errors='coerce')
    
    # Filter to approved reservations only
    reservations_df = reservations_df[reservations_df['status'] == 'approved'].copy()
    
    # Group by date and facility
    daily_bookings = reservations_df.groupby(['reservation_date', 'facility_id']).size().reset_index(name='booking_count')
    
    # Create date range
    min_date = daily_bookings['reservation_date'].min()
    max_date = daily_bookings['reservation_date'].max()
    date_range = pd.date_range(start=min_date, end=max_date, freq='D')
    
    # Get all facility IDs
    facility_ids = facilities_df['id'].tolist()
    
    # Create full combination of dates and facilities
    date_facility_combos = []
    for date in date_range:
        for facility_id in facility_ids:
            date_facility_combos.append({
                'date': date,
                'facility_id': facility_id
            })
    
    full_df = pd.DataFrame(date_facility_combos)
    
    # Merge with actual bookings
    full_df = full_df.merge(daily_bookings, on=['reservation_date', 'facility_id'], how='left')
    full_df['booking_count'] = full_df['booking_count'].fillna(0)
    full_df['date'] = full_df['reservation_date']
    full_df = full_df.drop(columns=['reservation_date'], errors='ignore')
    
    # Add time features
    full_df['year'] = full_df['date'].dt.year
    full_df['month'] = full_df['date'].dt.month
    full_df['day'] = full_df['date'].dt.day
    full_df['day_of_week'] = full_df['date'].dt.dayofweek
    full_df['day_of_year'] = full_df['date'].dt.dayofyear
    full_df['week'] = full_df['date'].dt.isocalendar().week
    full_df['is_weekend'] = (full_df['day_of_week'] >= 5).astype(int)
    full_df['is_month_start'] = (full_df['day'] <= 7).astype(int)
    full_df['is_month_end'] = (full_df['day'] >= 23).astype(int)
    
    # Add holiday flag
    def is_holiday(date_val):
        if isinstance(date_val, pd.Timestamp):
            month_day = f"{date_val.month:02d}-{date_val.day:02d}"
        else:
            month_day = date_val.strftime('%m-%d')
        all_holidays = config.PHILIPPINE_HOLIDAYS + config.BARANGAY_CULIAT_EVENTS
        return 1 if month_day in all_holidays else 0
    
    full_df['is_holiday'] = full_df['date'].apply(is_holiday)
    
    # Add lag features (previous day, previous week)
    full_df = full_df.sort_values(['facility_id', 'date'])
    full_df['booking_count_lag1'] = full_df.groupby('facility_id')['booking_count'].shift(1)
    full_df['booking_count_lag7'] = full_df.groupby('facility_id')['booking_count'].shift(7)
    full_df['booking_count_lag30'] = full_df.groupby('facility_id')['booking_count'].shift(30)
    
    # Add rolling averages
    full_df['booking_count_ma7'] = full_df.groupby('facility_id')['booking_count'].rolling(window=7, min_periods=1).mean().reset_index(0, drop=True)
    full_df['booking_count_ma30'] = full_df.groupby('facility_id')['booking_count'].rolling(window=30, min_periods=1).mean().reset_index(0, drop=True)
    
    # Fill NaN values
    full_df = full_df.fillna(0)
    
    return full_df


def train_prophet_model(df: pd.DataFrame, facility_id: int, periods: int = 30):
    """
    Train Prophet model for a specific facility
    Prophet requires 'ds' (date) and 'y' (target) columns
    """
    if not PROPHET_AVAILABLE:
        return None
    
    facility_data = df[df['facility_id'] == facility_id].copy()
    if len(facility_data) < 10:
        return None
    
    prophet_df = facility_data[['date', 'booking_count']].rename(columns={'date': 'ds', 'booking_count': 'y'})
    
    model = Prophet(
        yearly_seasonality=True,
        weekly_seasonality=True,
        daily_seasonality=False,
        seasonality_mode='additive'
    )
    
    try:
        model.fit(prophet_df)
        future = model.make_future_dataframe(periods=periods)
        forecast = model.predict(future)
        return forecast
    except:
        return None


def train_rf_forecasting_model(df: pd.DataFrame):
    """
    Train Random Forest model for demand forecasting
    """
    # Prepare features
    feature_cols = [
        'facility_id', 'year', 'month', 'day', 'day_of_week', 'day_of_year',
        'week', 'is_weekend', 'is_month_start', 'is_month_end', 'is_holiday',
        'booking_count_lag1', 'booking_count_lag7', 'booking_count_lag30',
        'booking_count_ma7', 'booking_count_ma30'
    ]
    
    X = df[feature_cols].copy()
    y = df['booking_count'].values
    
    # Split data (use last 20% for testing)
    split_idx = int(len(X) * 0.8)
    X_train, X_test = X[:split_idx], X[split_idx:]
    y_train, y_test = y[:split_idx], y[split_idx:]
    
    # Train model
    model = RandomForestRegressor(
        n_estimators=100,
        max_depth=10,
        min_samples_leaf=5,
        random_state=42,
        n_jobs=-1
    )
    
    model.fit(X_train, y_train)
    
    # Evaluate
    y_pred_train = model.predict(X_train)
    y_pred_test = model.predict(X_test)
    
    train_rmse = np.sqrt(mean_squared_error(y_train, y_pred_train))
    test_rmse = np.sqrt(mean_squared_error(y_test, y_pred_test))
    train_mae = mean_absolute_error(y_train, y_pred_train)
    test_mae = mean_absolute_error(y_test, y_pred_test)
    train_r2 = r2_score(y_train, y_pred_train)
    test_r2 = r2_score(y_test, y_pred_test)
    
    return {
        'model': model,
        'feature_cols': feature_cols,
        'train_rmse': train_rmse,
        'test_rmse': test_rmse,
        'train_mae': train_mae,
        'test_mae': test_mae,
        'train_r2': train_r2,
        'test_r2': test_r2,
    }


def main():
    """Train demand forecasting model"""
    print("=" * 60)
    print("Demand Forecasting Model Training")
    print("=" * 60)
    
    loader = DataLoader()
    
    try:
        loader.connect()
        
        print("\n1. Loading data...")
        end_date = datetime.now().strftime('%Y-%m-%d')
        start_date = (datetime.now() - timedelta(days=365)).strftime('%Y-%m-%d')
        
        reservations_df = loader.load_reservations(start_date=start_date, end_date=end_date)
        facilities_df = loader.load_facilities()
        
        if reservations_df.empty:
            print("No reservation data found. Cannot train model.")
            return
        
        if len(reservations_df) < 30:
            print("\nWarning: Not enough data for meaningful forecasting!")
            print("   Need at least 30 reservations. Current system uses simple averages.")
            return
        
        print(f"   Loaded {len(reservations_df)} reservations")
        print(f"   Loaded {len(facilities_df)} facilities")
        
        print("\n2. Preparing time series data...")
        ts_df = prepare_time_series_data(reservations_df, facilities_df)
        print(f"   Time series data shape: {ts_df.shape}")
        print(f"   Date range: {ts_df['date'].min()} to {ts_df['date'].max()}")
        print(f"   Facilities: {ts_df['facility_id'].nunique()}")
        
        print("\n3. Training Random Forest forecasting model...")
        rf_results = train_rf_forecasting_model(ts_df)
        
        print(f"   Train RMSE: {rf_results['train_rmse']:.4f}")
        print(f"   Test RMSE: {rf_results['test_rmse']:.4f}")
        print(f"   Train MAE: {rf_results['train_mae']:.4f}")
        print(f"   Test MAE: {rf_results['test_mae']:.4f}")
        print(f"   Train R²: {rf_results['train_r2']:.4f}")
        print(f"   Test R²: {rf_results['test_r2']:.4f}")
        
        # Save model
        model_path = config.MODELS_DIR / 'demand_forecasting_model.pkl'
        joblib.dump({
            'model': rf_results['model'],
            'feature_cols': rf_results['feature_cols'],
        }, model_path)
        
        print(f"\n4. Model saved to: {model_path}")
        
        if PROPHET_AVAILABLE:
            print("\n5. Prophet model available (optional for better forecasting)")
            print("   Prophet models are trained per-facility and can be used for advanced forecasting")
        
    except Exception as e:
        print(f"Error during training: {e}")
        import traceback
        traceback.print_exc()
    finally:
        loader.disconnect()


if __name__ == "__main__":
    main()
