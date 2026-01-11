"""
Train Chatbot Intent Classification Model
Classifies user questions into intents for the reservation system chatbot
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
from sklearn.metrics import accuracy_score, classification_report
import joblib
import re

import config


# Define intents and sample questions for training
INTENT_TRAINING_DATA = {
    'list_facilities': [
        'what facilities are available',
        'show me facilities',
        'list all facilities',
        'what facilities do you have',
        'available facilities',
        'what can I book',
        'show facilities',
        'facilities list',
        'what facilities are there',
    ],
    'facility_details': [
        'tell me about facility',
        'what is facility',
        'facility information',
        'facility details',
        'describe facility',
        'facility capacity',
        'facility amenities',
        'what does facility have',
        'facility location',
        'where is facility',
    ],
    'book_facility': [
        'how do I book',
        'how to book facility',
        'book a facility',
        'make reservation',
        'reserve facility',
        'create booking',
        'book now',
        'how to reserve',
        'booking process',
        'how to make reservation',
    ],
    'check_availability': [
        'is facility available',
        'check availability',
        'is it available',
        'facility available',
        'check if available',
        'availability check',
        'is facility free',
        'can I book on date',
        'available on date',
        'check schedule',
    ],
    'booking_rules': [
        'what are the rules',
        'booking rules',
        'reservation rules',
        'what are the requirements',
        'booking requirements',
        'how many days in advance',
        'booking limit',
        'maximum bookings',
        'booking policy',
        'terms and conditions',
    ],
    'cancel_booking': [
        'how to cancel',
        'cancel reservation',
        'cancel booking',
        'delete reservation',
        'remove booking',
        'cancel my booking',
        'how do I cancel',
    ],
    'my_bookings': [
        'my reservations',
        'my bookings',
        'view my bookings',
        'show my reservations',
        'my reservation list',
        'what did I book',
        'my booked facilities',
    ],
    'greeting': [
        'hello',
        'hi',
        'hey',
        'good morning',
        'good afternoon',
        'good evening',
        'greetings',
        'hi there',
    ],
    'goodbye': [
        'goodbye',
        'bye',
        'see you',
        'thank you',
        'thanks',
        'thanks a lot',
        'thank you very much',
        'appreciate it',
    ],
    'help': [
        'help',
        'i need help',
        'can you help',
        'what can you do',
        'how can you help',
        'assist me',
        'i need assistance',
        'guide me',
    ],
    'unknown': [
        'random text',
        'asdfghjkl',
        '123456',
        'what is weather',
        'tell me a joke',
        'what time is it',
    ]
}


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


def create_training_data():
    """
    Create training dataset from intent examples
    """
    data = []
    
    for intent, examples in INTENT_TRAINING_DATA.items():
        for example in examples:
            data.append({
                'text': example,
                'intent': intent
            })
    
    # Add variations with common prefixes/suffixes
    variations = []
    for intent, examples in INTENT_TRAINING_DATA.items():
        if intent not in ['greeting', 'goodbye', 'unknown']:
            for example in examples:
                variations.extend([
                    {'text': f'i want to {example}', 'intent': intent},
                    {'text': f'can i {example}', 'intent': intent},
                    {'text': f'i need to {example}', 'intent': intent},
                    {'text': f'please {example}', 'intent': intent},
                ])
    
    data.extend(variations)
    
    df = pd.DataFrame(data)
    df['text_clean'] = df['text'].apply(clean_text)
    
    return df


def main():
    """Train chatbot intent classification model"""
    print("=" * 60)
    print("Chatbot Intent Classification Model Training")
    print("=" * 60)
    
    print("\n1. Creating training data...")
    df = create_training_data()
    
    print(f"   Total training examples: {len(df)}")
    print(f"   Intents: {df['intent'].nunique()}")
    
    print("\n   Intent Distribution:")
    intent_counts = df['intent'].value_counts()
    for intent, count in intent_counts.items():
        print(f"      {intent}: {count}")
    
    print("\n2. Vectorizing text...")
    vectorizer = TfidfVectorizer(
        max_features=1000,
        ngram_range=(1, 2),
        min_df=1,
        stop_words='english'
    )
    
    X_text = vectorizer.fit_transform(df['text_clean'])
    y_intent = df['intent']
    
    print(f"   Feature matrix shape: {X_text.shape}")
    
    print("\n3. Splitting data (train/test)...")
    X_train, X_test, y_train, y_test = train_test_split(
        X_text, y_intent,
        test_size=0.2,
        random_state=42,
        stratify=y_intent
    )
    
    print(f"   Train set: {X_train.shape[0]} samples")
    print(f"   Test set: {X_test.shape[0]} samples")
    
    print("\n4. Training Random Forest classifier...")
    classifier = RandomForestClassifier(
        n_estimators=100,
        max_depth=10,
        random_state=42,
        n_jobs=-1,
        class_weight='balanced'
    )
    
    classifier.fit(X_train, y_train)
    print("   Training complete.")
    
    print("\n5. Evaluating model...")
    y_pred_train = classifier.predict(X_train)
    y_pred_test = classifier.predict(X_test)
    
    train_acc = accuracy_score(y_train, y_pred_train)
    test_acc = accuracy_score(y_test, y_pred_test)
    
    print(f"   Train Accuracy: {train_acc:.4f}")
    print(f"   Test Accuracy: {test_acc:.4f}")
    
    print("\n   Classification Report:")
    print(classification_report(y_test, y_pred_test))
    
    # Test with sample questions
    print("\n6. Testing with sample questions...")
    test_questions = [
        'what facilities are available',
        'how do I book a facility',
        'is the court available tomorrow',
        'what are the booking rules',
        'hello',
        'thank you',
        'help me',
        'cancel my reservation',
    ]
    
    for question in test_questions:
        question_clean = clean_text(question)
        question_vec = vectorizer.transform([question_clean])
        intent_pred = classifier.predict(question_vec)[0]
        confidence = classifier.predict_proba(question_vec)[0].max()
        print(f"   Q: '{question}'")
        print(f"      Intent: {intent_pred} (confidence: {confidence:.2%})")
    
    # Save model
    model_path = config.MODELS_DIR / 'chatbot_intent_model.pkl'
    vectorizer_path = config.MODELS_DIR / 'chatbot_intent_vectorizer.pkl'
    
    joblib.dump(classifier, model_path)
    joblib.dump(vectorizer, vectorizer_path)
    
    print(f"\n7. Model saved to: {model_path}")
    print(f"   Vectorizer saved to: {vectorizer_path}")
    
    print("\n" + "=" * 60)
    print("Training complete!")
    print("=" * 60)
    print("\nNote: This model can be enhanced with:")
    print("  - Real conversation data from users")
    print("  - More training examples")
    print("  - Entity extraction (facility names, dates)")
    print("  - Context awareness (multi-turn conversations)")


if __name__ == "__main__":
    main()
