# AI Models for LGU Facilities Reservation System

This folder contains Python AI/ML models for the Facilities Reservation System.

## Features

1. **Smart Facility Recommendation** ✅ - Recommend facilities based on user requirements  
2. **Automated Approval Workflow** ✅ - Risk assessment for auto-approval
3. **NLP Purpose Analysis** ✅ - Analyze and categorize reservation purposes
4. **Chatbot Intent Classification** ✅ - Classify user questions for chatbot responses

## Setup

1. Activate virtual environment:
```bash
cd ai
venv\Scripts\activate  # Windows
# or
source venv/bin/activate  # Linux/Mac
```

2. Install dependencies:
```bash
pip install -r requirements.txt
```

3. Configure database connection in `config.py`

4. Extract training data:
```bash
python scripts/extract_data.py
```

5. Train models:
```bash
python scripts/train_facility_recommendation.py  # Requires at least 5 approved reservations
```

## Project Structure

```
ai/
├── README.md
├── requirements.txt
├── config.py                 # Database configuration
├── models/                   # Trained model files
│   ├── facility_recommendation_model.pkl
│   └── ...
├── scripts/                  # Training scripts
│   ├── extract_data.py
│   ├── train_facility_recommendation.py
│   └── ...
├── src/                      # Source code modules
│   ├── __init__.py
│   ├── data_loader.py        # Database connection and data loading
│   ├── facility_recommendation.py # Recommendation model
│   └── utils.py              # Utility functions
└── api/                      # Flask/FastAPI endpoints (future)
    └── app.py
```

## Integration

The PHP system will call these Python models via REST API (to be implemented) or direct file access.
