"""
Configuration file for AI models
Update database credentials to match your setup
"""

import os
from pathlib import Path

# Base directory
BASE_DIR = Path(__file__).parent

# Load project .env (and optional private/cprf.env) for DB + Python tooling
try:
    from dotenv import load_dotenv

    _project_root = BASE_DIR.parent
    load_dotenv(_project_root / '.env')
    _private = os.getenv('CPRF_PRIVATE_ENV')
    if _private:
        load_dotenv(_private, override=True)
    else:
        _home = os.path.expanduser('~')
        for _candidate in (
            Path(_home) / 'private' / 'cprf.env',
            _project_root / 'private' / 'cprf.env',
        ):
            if _candidate.is_file():
                load_dotenv(_candidate, override=True)
                break
except ImportError:
    pass

# Database configuration
DB_CONFIG = {
    'host': os.getenv('DB_HOST', 'localhost'),
    'port': int(os.getenv('DB_PORT', '3306')),
    'user': os.getenv('DB_USER', 'root'),
    'password': os.getenv('DB_PASS', os.getenv('DB_PASSWORD', '')),
    'database': os.getenv('DB_NAME', 'facilities_reservation'),
    'charset': 'utf8mb4',
}

# Model storage directory
MODELS_DIR = BASE_DIR / 'models'
MODELS_DIR.mkdir(exist_ok=True)

# Data storage directory
DATA_DIR = BASE_DIR / 'data'
DATA_DIR.mkdir(exist_ok=True)

# Training parameters
CONFLICT_DETECTION_PARAMS = {
    'test_size': 0.2,
    'random_state': 42,
    'n_estimators': 100,  # For RandomForest
    'max_depth': 10,
}

FACILITY_RECOMMENDATION_PARAMS = {
    'model_name': 'facility_recommendation_model.pkl',
    'encoders_name': 'facility_recommendation_encoders.pkl',
    'test_size': 0.2,
    'random_state': 42,
    'n_estimators': 100,  # For RandomForest
    'max_depth': 10,
    'min_samples_leaf': 5,
    'min_approved_reservations': 5,  # Minimum approved reservations needed for training
}

AUTO_APPROVAL_RISK_PARAMS = {
    'model_name': 'auto_approval_risk_model.pkl',
    'encoders_name': 'auto_approval_risk_encoders.pkl',
    'test_size': 0.2,
    'random_state': 42,
    'n_estimators': 100,  # For RandomForest
    'max_depth': 10,
    'min_samples_leaf': 5,
    'min_reservations': 10,  # Minimum reservations needed for training
}

# Feature engineering settings
TIME_SLOT_BINS = [
    (0, 8),    # Early morning (0-8)
    (8, 12),   # Morning (8-12)
    (12, 17),  # Afternoon (12-17)
    (17, 21),  # Evening (17-21)
    (21, 24),  # Night (21-24)
]

# Holidays for Philippines + Barangay Culiat
PHILIPPINE_HOLIDAYS = [
    '01-01',  # New Year's Day
    '02-25',  # EDSA People Power Anniversary
    '04-09',  # Araw ng Kagitingan
    '06-12',  # Independence Day
    '08-21',  # Ninoy Aquino Day
    '08-26',  # National Heroes Day
    '11-01',  # All Saints' Day
    '11-02',  # All Souls' Day
    '11-30',  # Bonifacio Day
    '12-25',  # Christmas Day
    '12-30',  # Rizal Day
]

BARANGAY_CULIAT_EVENTS = [
    '09-08',  # Barangay Culiat Fiesta
    '02-11',  # Barangay Culiat Founding Day
]
