#!/usr/bin/env python3
"""
Philippines Holiday Detection API

Detects Philippines national holidays and special non-working days.
Returns holiday information for a given date or date range.
"""

import sys
import json
from datetime import date, datetime, timedelta
from typing import Dict, List, Optional

class PhilippinesHolidayDetector:
    """Detects Philippines holidays based on Philippine holiday calendar."""
    
    def __init__(self, year: int):
        self.year = year
        self.holidays = self._generate_holidays(year)
    
    def _generate_holidays(self, year: int) -> Dict[str, Dict]:
        """
        Generate Philippines holidays for a given year.
        
        Returns dictionary mapping date strings to holiday information.
        """
        holidays = {}
        
        # Fixed Date Holidays
        fixed_holidays = {
            "01-01": ("New Year's Day", "Regular Holiday"),
            "04-09": ("Araw ng Kagitingan", "Regular Holiday"),
            "05-01": ("Labor Day", "Regular Holiday"),
            "06-12": ("Independence Day", "Regular Holiday"),
            "08-30": ("National Heroes Day", "Regular Holiday"), # Last Monday of August
            "11-01": ("All Saints' Day", "Special Non-Working Holiday"),
            "11-02": ("All Souls' Day", "Special Non-Working Holiday"),
            "11-30": ("Bonifacio Day", "Regular Holiday"),
            "12-25": ("Christmas Day", "Regular Holiday"),
            "12-30": ("Rizal Day", "Regular Holiday"),
        }
        
        for date_str, (name, holiday_type) in fixed_holidays.items():
            full_date = f"{year}-{date_str}"
            holidays[full_date] = {
                "name": name,
                "type": holiday_type,
                "date": full_date
            }
        
        # Moveable Holidays (need calculation)
        # Maundy Thursday and Good Friday (varies by year)
        easter_sunday = self._calculate_easter_sunday(year)
        if easter_sunday:
            maundy_thursday = easter_sunday - timedelta(days=3)
            good_friday = easter_sunday - timedelta(days=2)
            
            holidays[maundy_thursday.strftime("%Y-%m-%d")] = {
                "name": "Maundy Thursday",
                "type": "Regular Holiday",
                "date": maundy_thursday.strftime("%Y-%m-%d")
            }
            
            holidays[good_friday.strftime("%Y-%m-%d")] = {
                "name": "Good Friday",
                "type": "Regular Holiday",
                "date": good_friday.strftime("%Y-%m-%d")
            }
        
        # National Heroes Day (Last Monday of August)
        last_monday_august = self._last_monday_of_month(year, 8)
        if last_monday_august:
            holidays[last_monday_august.strftime("%Y-%m-%d")] = {
                "name": "National Heroes Day",
                "type": "Regular Holiday",
                "date": last_monday_august.strftime("%Y-%m-%d")
            }
        
        # Additional Special Non-Working Holidays (common dates)
        # These may vary by year, using common dates
        additional_holidays = {
            "02-14": ("Valentine's Day", "Special Non-Working Holiday"),
            "02-25": ("EDSA People Power Revolution Anniversary", "Special Non-Working Holiday"),
            "08-21": ("Ninoy Aquino Day", "Special Non-Working Holiday"),
            "12-24": ("Christmas Eve", "Special Non-Working Holiday"),
            "12-31": ("New Year's Eve", "Special Non-Working Holiday"),
        }
        
        for date_str, (name, holiday_type) in additional_holidays.items():
            full_date = f"{year}-{date_str}"
            holidays[full_date] = {
                "name": name,
                "type": holiday_type,
                "date": full_date
            }
        
        return holidays
    
    def _calculate_easter_sunday(self, year: int) -> Optional[date]:
        """
        Calculate Easter Sunday using the Computus algorithm.
        
        Returns the date of Easter Sunday for the given year.
        """
        try:
            # Anonymous Gregorian algorithm
            a = year % 19
            b = year // 100
            c = year % 100
            d = b // 4
            e = b % 4
            f = (b + 8) // 25
            g = (b - f + 1) // 3
            h = (19 * a + b - d - g + 15) % 30
            i = c // 4
            k = c % 4
            l = (32 + 2 * e + 2 * i - h - k) % 7
            m = (a + 11 * h + 22 * l) // 451
            month = (h + l - 7 * m + 114) // 31
            day = ((h + l - 7 * m + 114) % 31) + 1
            
            return date(year, month, day)
        except:
            return None
    
    def _last_monday_of_month(self, year: int, month: int) -> Optional[date]:
        """
        Find the last Monday of a given month and year.
        """
        try:
            # Start from the last day of the month
            last_day = date(year, month + 1, 1) - timedelta(days=1)
            
            # Find the last Monday
            days_back = (last_day.weekday() - 0) % 7  # Monday is 0
            last_monday = last_day - timedelta(days=days_back)
            
            return last_monday
        except:
            return None
    
    def is_holiday(self, date_str: str) -> Optional[Dict]:
        """
        Check if a given date is a holiday.
        
        Args:
            date_str: Date string in YYYY-MM-DD format
            
        Returns:
            Holiday information dict if holiday, None otherwise
        """
        return self.holidays.get(date_str)
    
    def get_holidays_in_range(self, start_date: str, end_date: str) -> List[Dict]:
        """
        Get all holidays within a date range.
        
        Args:
            start_date: Start date string in YYYY-MM-DD format
            end_date: End date string in YYYY-MM-DD format
            
        Returns:
            List of holiday information dicts
        """
        holidays_in_range = []
        
        try:
            start = datetime.strptime(start_date, "%Y-%m-%d").date()
            end = datetime.strptime(end_date, "%Y-%m-%d").date()
            
            current = start
            while current <= end:
                date_str = current.strftime("%Y-%m-%d")
                if date_str in self.holidays:
                    holidays_in_range.append(self.holidays[date_str])
                current += timedelta(days=1)
        except:
            pass
        
        return holidays_in_range
    
    def get_all_holidays(self) -> List[Dict]:
        """
        Get all holidays for the year.
        
        Returns:
            List of holiday information dicts sorted by date
        """
        return sorted(self.holidays.values(), key=lambda x: x['date'])

def main():
    """Main function to handle API requests."""
    try:
        # Read input from stdin
        input_data = json.loads(sys.stdin.read())
        
        date_str = input_data.get('date')
        start_date = input_data.get('start_date')
        end_date = input_data.get('end_date')
        year = input_data.get('year')
        
        # Determine year to use
        if year:
            target_year = int(year)
        elif date_str:
            target_year = int(date_str.split('-')[0])
        elif start_date:
            target_year = int(start_date.split('-')[0])
        else:
            target_year = datetime.now().year
        
        # Initialize detector
        detector = PhilippinesHolidayDetector(target_year)
        
        result = {}
        
        if date_str:
            # Check single date
            holiday = detector.is_holiday(date_str)
            if holiday:
                result = {
                    "is_holiday": True,
                    "holiday": holiday
                }
            else:
                result = {
                    "is_holiday": False,
                    "holiday": None
                }
        
        elif start_date and end_date:
            # Get holidays in range
            holidays = detector.get_holidays_in_range(start_date, end_date)
            result = {
                "holidays": holidays,
                "count": len(holidays)
            }
        
        else:
            # Get all holidays for the year
            holidays = detector.get_all_holidays()
            result = {
                "year": target_year,
                "holidays": holidays,
                "count": len(holidays)
            }
        
        print(json.dumps(result, indent=2))
        
    except Exception as e:
        error_result = {
            "error": str(e),
            "holidays": [],
            "count": 0
        }
        print(json.dumps(error_result, indent=2))

if __name__ == "__main__":
    main()
