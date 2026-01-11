"""
Purpose Analysis Model Inference
Loads trained models to categorize purposes and detect unclear purposes
"""

import sys
from pathlib import Path
import pandas as pd
import numpy as np
import joblib
import re

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

import config


def clean_text(text: str):
    """Clean and normalize text"""
    if pd.isna(text) or not text:
        return ""
    
    text = str(text).lower()
    # Remove special characters but keep spaces
    text = re.sub(r'[^a-z0-9\s]', ' ', text)
    # Remove extra spaces
    text = re.sub(r'\s+', ' ', text).strip()
    return text


class PurposeAnalysisModel:
    """Purpose Analysis Model for inference"""
    
    def __init__(self):
        self.category_model = None
        self.category_vectorizer = None
        self.unclear_model = None
        self.unclear_vectorizer = None
        self.category_loaded = False
        self.unclear_loaded = False
    
    def load_category_model(self):
        """Load purpose category classification model"""
        try:
            model_path = config.MODELS_DIR / 'purpose_category_model.pkl'
            vectorizer_path = config.MODELS_DIR / 'purpose_category_vectorizer.pkl'
            
            if not model_path.exists() or not vectorizer_path.exists():
                return False
            
            self.category_model = joblib.load(model_path)
            self.category_vectorizer = joblib.load(vectorizer_path)
            self.category_loaded = True
            return True
        except Exception as e:
            print(f"Error loading category model: {e}")
            return False
    
    def load_unclear_model(self):
        """Load unclear purpose detection model"""
        try:
            model_path = config.MODELS_DIR / 'purpose_unclear_model.pkl'
            vectorizer_path = config.MODELS_DIR / 'purpose_unclear_vectorizer.pkl'
            
            if not model_path.exists() or not vectorizer_path.exists():
                return False
            
            self.unclear_model = joblib.load(model_path)
            self.unclear_vectorizer = joblib.load(vectorizer_path)
            self.unclear_loaded = True
            return True
        except Exception as e:
            print(f"Error loading unclear model: {e}")
            return False
    
    def classify_purpose_category(self, purpose: str):
        """
        Classify purpose into a category
        
        Args:
            purpose: Purpose text string
        
        Returns:
            Dictionary with:
                - category: Predicted category (community, sports, education, etc.)
                - confidence: Confidence score (0-1)
        """
        if not self.category_loaded:
            if not self.load_category_model():
                return {
                    'category': 'private',
                    'confidence': 0.0
                }
        
        try:
            # Clean and vectorize purpose
            purpose_clean = clean_text(purpose)
            
            if not purpose_clean or len(purpose_clean) < 3:
                return {
                    'category': 'unclear',
                    'confidence': 1.0
                }
            
            # Vectorize
            X = self.category_vectorizer.transform([purpose_clean])
            
            # Predict
            category = self.category_model.predict(X)[0]
            proba = self.category_model.predict_proba(X)[0]
            
            # Get confidence (max probability)
            confidence = float(max(proba))
            
            return {
                'category': str(category),
                'confidence': confidence
            }
        except Exception as e:
            print(f"Error classifying purpose: {e}")
            return {
                'category': 'private',
                'confidence': 0.0
            }
    
    def detect_unclear_purpose(self, purpose: str):
        """
        Detect if purpose is unclear or suspicious
        
        Args:
            purpose: Purpose text string
        
        Returns:
            Dictionary with:
                - is_unclear: True if unclear, False if clear
                - probability: Probability of being unclear (0-1)
                - confidence: Confidence in prediction
        """
        if not self.unclear_loaded:
            if not self.load_unclear_model():
                # Fallback to rule-based detection
                return self._rule_based_unclear_detection(purpose)
        
        try:
            # Clean and vectorize purpose
            purpose_clean = clean_text(purpose)
            
            if not purpose_clean or len(purpose_clean) < 3:
                return {
                    'is_unclear': True,
                    'probability': 1.0,
                    'confidence': 1.0
                }
            
            # Vectorize
            X = self.unclear_vectorizer.transform([purpose_clean])
            
            # Predict
            is_unclear = self.unclear_model.predict(X)[0]
            proba = self.unclear_model.predict_proba(X)[0]
            
            # Get probability of unclear (class 1)
            unclear_prob = float(proba[1] if len(proba) > 1 else proba[0])
            confidence = float(max(proba))
            
            return {
                'is_unclear': bool(is_unclear == 1),
                'probability': unclear_prob,
                'confidence': confidence
            }
        except Exception as e:
            print(f"Error detecting unclear purpose: {e}")
            return self._rule_based_unclear_detection(purpose)
    
    def _rule_based_unclear_detection(self, purpose: str):
        """Fallback rule-based unclear detection"""
        if pd.isna(purpose) or not purpose:
            return {
                'is_unclear': True,
                'probability': 1.0,
                'confidence': 1.0
            }
        
        purpose_lower = str(purpose).lower().strip()
        
        # Too short
        if len(purpose_lower) < 5:
            return {
                'is_unclear': True,
                'probability': 0.8,
                'confidence': 0.8
            }
        
        # Generic/vague purposes
        vague_patterns = ['test', 'testing', 'asdf', 'qqq', '123', 'none', 'n/a', 'na', 'nothing']
        if any(pattern in purpose_lower for pattern in vague_patterns):
            return {
                'is_unclear': True,
                'probability': 0.9,
                'confidence': 0.9
            }
        
        return {
            'is_unclear': False,
            'probability': 0.2,
            'confidence': 0.7
        }


# Global model instance
_model_instance = None


def get_purpose_model():
    """Get or create global model instance"""
    global _model_instance
    if _model_instance is None:
        _model_instance = PurposeAnalysisModel()
    return _model_instance
