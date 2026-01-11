# Seeding Sample Reservations

This guide explains how to generate sample reservations for testing and model training.

## Quick Start

### Basic Usage

Generate 50 sample reservations (default):
```bash
cd ai
.\venv\Scripts\Activate.ps1  # Windows
python scripts/seed_sample_reservations.py
```

### Custom Options

Generate 100 reservations with 70% approved:
```bash
python scripts/seed_sample_reservations.py --count 100 --approved-ratio 0.7
```

Generate 30 reservations with 80% approved (good for Facility Recommendation training):
```bash
python scripts/seed_sample_reservations.py --count 30 --approved-ratio 0.8
```

## Requirements

Before running the script, ensure you have:
1. ✅ At least one active user account in the database
2. ✅ At least one available facility in the database
3. ✅ Database connection configured in `ai/config.py`

## What the Script Does

The script generates realistic sample reservations with:

### Data Variety
- **Different purposes**: Community events, sports, education, celebrations, religious, private events
- **Different time slots**: Morning, afternoon, evening
- **Different statuses**: Approved, pending, denied (configurable ratio)
- **Different dates**: Spread over the past 90 days and next 60 days
- **Realistic attendee counts**: Based on facility capacity
- **Priority levels**: LGU/Barangay, Community/Org, Private Individual
- **Commercial flags**: Mix of commercial and non-commercial reservations

### Sample Purposes Generated
- **Community**: Barangay General Assembly, Community Meeting, Town Hall, etc.
- **Sports**: Basketball Tournament, Volleyball Game, Sports Festival, etc.
- **Education**: Zumba Class, Yoga Workshop, Educational Seminar, etc.
- **Celebration**: Wedding Reception, Birthday Party, Fiesta, etc.
- **Religious**: Church Service, Prayer Meeting, Bible Study, etc.
- **Private**: Family Gathering, Personal Event, etc.

## Usage Examples

### For Model Training

**Facility Recommendation Model** (needs 5+ approved reservations):
```bash
# Generate 30 reservations with 70% approved = ~21 approved
python scripts/seed_sample_reservations.py --count 30 --approved-ratio 0.7
```

**Demand Forecasting Model** (needs 30+ total reservations):
```bash
# Generate 50 reservations total
python scripts/seed_sample_reservations.py --count 50 --approved-ratio 0.6
```

### For Testing

Generate diverse test data:
```bash
# 100 reservations with mixed statuses
python scripts/seed_sample_reservations.py --count 100 --approved-ratio 0.5
```

## After Seeding

Once you've seeded the data:

1. **Check the results**: The script will show you:
   - Total reservations inserted
   - Status distribution (approved, pending, denied)
   - Whether you have enough data for model training

2. **Train the models**:
   ```bash
   # Facility Recommendation (if you have 5+ approved)
   python scripts/train_facility_recommendation.py
   
   # Demand Forecasting (if you have 30+ total)
   python scripts/train_demand_forecasting.py
   
   # Purpose Analysis (if you have 10+ total)
   python scripts/train_purpose_analysis.py
   ```

3. **Test the models**:
   - Visit the Test AI Models page
   - Test facility recommendations in the booking form
   - Verify demand forecasting predictions

## Safety Features

- ⚠️ **Confirmation Prompt**: The script asks for confirmation before inserting data
- ✅ **Validation**: Checks for users and facilities before running
- ✅ **Error Handling**: Continues if some inserts fail, shows warnings
- ✅ **Transaction Safety**: Uses database transactions (can rollback on error)

## Notes

- The script uses **existing users** and **existing facilities** from your database
- Dates are spread realistically (past and future)
- Status distribution is weighted towards approved (for training purposes)
- Commercial flag is set randomly (20% commercial by default)
- Priority levels are assigned based on purpose type

## Troubleshooting

### "No active users found"
**Solution**: Create at least one user account first through the registration/login system.

### "No available facilities found"
**Solution**: Create facilities first through the facility management page.

### "Failed to insert reservation"
**Solution**: Check database connection and ensure all required fields exist in the schema. The script will continue and show which reservations failed.

## Manual Alternative

If you prefer to create reservations manually:

1. **Through the UI**: Use the "Book Facility" page for each user account
2. **Through Admin**: Approve/deny reservations as needed
3. **Through Database**: Direct SQL INSERT (not recommended for testing)

The seeding script is much faster and generates more diverse data for model training!
