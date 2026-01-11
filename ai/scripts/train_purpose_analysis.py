"""
Train NLP Purpose Analysis Model
Categorizes reservation purposes and detects unclear/suspicious purposes
"""

import sys
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

import pandas as pd
import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix
import joblib
from datetime import datetime, timedelta
import re

from src.data_loader import DataLoader
import config


def categorize_purpose(purpose: str, status: str):
    """
    Categorize purpose into event types
    Categories: community, sports, education, religious, celebration, private, government, unclear
    """
    if pd.isna(purpose) or not purpose:
        return 'unclear'
    
    purpose_lower = str(purpose).lower().strip()
    
    # If purpose is too short, mark as unclear
    if len(purpose_lower) < 3:
        return 'unclear'
    
    # Community events
    community_keywords = ['barangay', 'community', 'general assembly', 'town hall', 'meeting', 'assembly']
    if any(keyword in purpose_lower for keyword in community_keywords):
        return 'community'
    
    # Sports events
    sports_keywords = ['sports', 'game', 'tournament', 'basketball', 'volleyball', 'football', 'soccer', 'badminton']
    if any(keyword in purpose_lower for keyword in sports_keywords):
        return 'sports'
    
    # Education/Training
    education_keywords = ['education', 'training', 'seminar', 'workshop', 'class', 'zumba', 'fitness', 'yoga']
    if any(keyword in purpose_lower for keyword in education_keywords):
        return 'education'
    
    # Religious events
    religious_keywords = ['religious', 'mass', 'prayer', 'worship', 'church', 'bible study']
    if any(keyword in purpose_lower for keyword in religious_keywords):
        return 'religious'
    
    # Celebrations
    celebration_keywords = ['celebration', 'party', 'fiesta', 'festival', 'birthday', 'anniversary', 'wedding']
    if any(keyword in purpose_lower for keyword in celebration_keywords):
        return 'celebration'
    
    # Government events
    government_keywords = ['government', 'lgu', 'municipal', 'city', 'official', 'public service']
    if any(keyword in purpose_lower for keyword in government_keywords):
        return 'government'
    
    # Private events (catch-all for personal events)
    private_keywords = ['private', 'personal', 'family', 'personal use']
    if any(keyword in purpose_lower for keyword in private_keywords):
        return 'private'
    
    # If status is denied and purpose is unclear, mark as suspicious
    if status == 'denied':
        return 'unclear'
    
    # Default: private (most common for individual reservations)
    return 'private'


def is_unclear_purpose(purpose: str):
    """
    Detect unclear or suspicious purposes
    Returns 1 if unclear, 0 if clear
    """
    if pd.isna(purpose) or not purpose:
        return 1
    
    purpose_lower = str(purpose).lower().strip()
    
    # Too short
    if len(purpose_lower) < 5:
        return 1
    
    # Generic/vague purposes
    vague_patterns = ['test', 'testing', 'asdf', 'qqq', '123', 'none', 'n/a', 'na', 'nothing']
    if any(pattern in purpose_lower for pattern in vague_patterns):
        return 1
    
    # Suspicious patterns
    suspicious_patterns = ['spam', 'fake', 'demo']
    if any(pattern in purpose_lower for pattern in suspicious_patterns):
        return 1
    
    return 0


def clean_text(text: str):
    """Clean and normalize text"""
    if pd.isna(text):
        return ""
    
    text = str(text).lower()
    # Remove special characters but keep spaces
    text = re.sub(r'[^a-z0-9\s]', ' ', text)
    # Remove extra spaces
    text = re.sub(r'\s+', ' ', text).strip()
    return text


def prepare_features(reservations_df: pd.DataFrame):
    """
    Prepare features for purpose analysis
    """
    # Clean purposes
    reservations_df = reservations_df.copy()
    reservations_df['purpose_clean'] = reservations_df['purpose'].apply(clean_text)
    
    # Add category labels
    reservations_df['purpose_category'] = reservations_df.apply(
        lambda row: categorize_purpose(row['purpose'], row.get('status', '')),
        axis=1
    )
    
    # Add unclear flag
    reservations_df['is_unclear'] = reservations_df['purpose'].apply(is_unclear_purpose)
    
    return reservations_df


def main():
    """Train purpose analysis models"""
    print("=" * 60)
    print("NLP Purpose Analysis Model Training")
    print("=" * 60)
    
    loader = DataLoader()
    
    try:
        loader.connect()
        
        print("\n1. Loading data...")
        end_date = datetime.now().strftime('%Y-%m-%d')
        start_date = (datetime.now() - timedelta(days=365)).strftime('%Y-%m-%d')
        
        reservations_df = loader.load_reservations(start_date=start_date, end_date=end_date)
        
        if reservations_df.empty:
            print("No reservation data found. Cannot train model.")
            return
        
        if len(reservations_df) < 10:
            print("\nWarning: Not enough data for training!")
            print("   Need at least 10 reservations with purposes.")
            return
        
        print(f"   Loaded {len(reservations_df)} reservations")
        
        print("\n2. Preparing features...")
        df = prepare_features(reservations_df)
        
        # Show category distribution
        print("\n   Purpose Category Distribution:")
        category_counts = df['purpose_category'].value_counts()
        for cat, count in category_counts.items():
            print(f"      {cat}: {count}")
        
        print(f"\n   Unclear purposes: {df['is_unclear'].sum()} ({df['is_unclear'].mean()*100:.1f}%)")
        
        # Train category classification model
        print("\n3. Training Purpose Category Classifier...")
        
        # Filter out unclear purposes for category classification
        clear_df = df[df['is_unclear'] == 0].copy()
        
        if len(clear_df) < 5:
            print("   Not enough clear purposes for category classification.")
        else:
            # Vectorize purposes
            vectorizer = TfidfVectorizer(
                max_features=1000,
                ngram_range=(1, 2),
                min_df=2,
                stop_words='english'
            )
            
            X_text = vectorizer.fit_transform(clear_df['purpose_clean'])
            y_category = clear_df['purpose_category']
            
            # Split data (only stratify if all classes have at least 2 samples)
            unique_classes = y_category.unique()
            can_stratify = all(y_category.value_counts()[cat] >= 2 for cat in unique_classes) and len(unique_classes) > 1
            
            X_train, X_test, y_train, y_test = train_test_split(
                X_text, y_category,
                test_size=0.2,
                random_state=42,
                stratify=y_category if can_stratify else None
            )
            
            # Train classifier
            category_classifier = RandomForestClassifier(
                n_estimators=100,
                max_depth=10,
                random_state=42,
                n_jobs=-1,
                class_weight='balanced'
            )
            
            category_classifier.fit(X_train, y_train)
            
            # Evaluate
            y_pred_train = category_classifier.predict(X_train)
            y_pred_test = category_classifier.predict(X_test)
            
            train_acc = accuracy_score(y_train, y_pred_train)
            test_acc = accuracy_score(y_test, y_pred_test)
            
            print(f"   Train Accuracy: {train_acc:.4f}")
            print(f"   Test Accuracy: {test_acc:.4f}")
            
            print("\n   Classification Report:")
            print(classification_report(y_test, y_pred_test))
            
            # Save category model
            category_model_path = config.MODELS_DIR / 'purpose_category_model.pkl'
            category_vectorizer_path = config.MODELS_DIR / 'purpose_category_vectorizer.pkl'
            
            joblib.dump(category_classifier, category_model_path)
            joblib.dump(vectorizer, category_vectorizer_path)
            
            print(f"\n   Category model saved to: {category_model_path}")
            print(f"   Vectorizer saved to: {category_vectorizer_path}")
        
        # Train unclear detection model
        print("\n4. Training Unclear Purpose Detector...")
        
        # Vectorize all purposes
        unclear_vectorizer = TfidfVectorizer(
            max_features=500,
            ngram_range=(1, 2),
            min_df=1
        )
        
        X_text_all = unclear_vectorizer.fit_transform(df['purpose_clean'])
        y_unclear = df['is_unclear']
        
        # Split data (only stratify if both classes have at least 2 samples)
        unclear_count = y_unclear.sum()
        clear_count = (y_unclear == 0).sum()
        can_stratify_u = unclear_count >= 2 and clear_count >= 2
        
        X_train_u, X_test_u, y_train_u, y_test_u = train_test_split(
            X_text_all, y_unclear,
            test_size=0.2,
            random_state=42,
            stratify=y_unclear if can_stratify_u else None
        )
        
        # Train classifier
        unclear_classifier = RandomForestClassifier(
            n_estimators=100,
            max_depth=10,
            random_state=42,
            n_jobs=-1,
            class_weight='balanced'
        )
        
        unclear_classifier.fit(X_train_u, y_train_u)
        
        # Evaluate
        y_pred_train_u = unclear_classifier.predict(X_train_u)
        y_pred_test_u = unclear_classifier.predict(X_test_u)
        
        train_acc_u = accuracy_score(y_train_u, y_pred_train_u)
        test_acc_u = accuracy_score(y_test_u, y_pred_test_u)
        
        print(f"   Train Accuracy: {train_acc_u:.4f}")
        print(f"   Test Accuracy: {test_acc_u:.4f}")
        
        print("\n   Classification Report:")
        unique_classes_u = sorted(np.unique(y_test_u))
        if len(unique_classes_u) == 2:
            print(classification_report(y_test_u, y_pred_test_u, target_names=['Clear', 'Unclear']))
        else:
            class_name = 'Clear' if 0 in unique_classes_u else 'Unclear'
            print(f"   Only one class present in test set: {class_name}")
            print(classification_report(y_test_u, y_pred_test_u, labels=unique_classes_u, target_names=[class_name]))
        
        # Save unclear model
        unclear_model_path = config.MODELS_DIR / 'purpose_unclear_model.pkl'
        unclear_vectorizer_path = config.MODELS_DIR / 'purpose_unclear_vectorizer.pkl'
        
        joblib.dump(unclear_classifier, unclear_model_path)
        joblib.dump(unclear_vectorizer, unclear_vectorizer_path)
        
        print(f"\n   Unclear detector saved to: {unclear_model_path}")
        print(f"   Vectorizer saved to: {unclear_vectorizer_path}")
        
    except Exception as e:
        print(f"Error during training: {e}")
        import traceback
        traceback.print_exc()
    finally:
        loader.disconnect()


if __name__ == "__main__":
    main()
