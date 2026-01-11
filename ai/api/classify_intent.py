"""
API endpoint for chatbot intent classification
Called from PHP to classify user questions
"""

import sys
import json
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent.parent))

import config
import joblib
import re


def clean_text(text: str):
    """Clean and normalize text"""
    if not text:
        return ""
    text = str(text).lower()
    text = re.sub(r'[^a-z0-9\s]', ' ', text)
    text = re.sub(r'\s+', ' ', text).strip()
    return text


if __name__ == "__main__":
    # Read input from command line arguments or stdin
    if len(sys.argv) > 1:
        question = ' '.join(sys.argv[1:])
    else:
        try:
            input_data = json.loads(sys.stdin.read())
            question = input_data.get('question', '')
        except:
            print(json.dumps({'error': 'Invalid input'}))
            sys.exit(1)
    
    if not question:
        print(json.dumps({'error': 'Question is required'}))
        sys.exit(1)
    
    try:
        # Load model and vectorizer
        model_path = config.MODELS_DIR / 'chatbot_intent_model.pkl'
        vectorizer_path = config.MODELS_DIR / 'chatbot_intent_vectorizer.pkl'
        
        if not model_path.exists() or not vectorizer_path.exists():
            print(json.dumps({'error': 'Model not found', 'intent': 'unknown', 'confidence': 0.0}))
            sys.exit(1)
        
        model = joblib.load(model_path)
        vectorizer = joblib.load(vectorizer_path)
        
        # Clean and vectorize question
        question_clean = clean_text(question)
        question_vec = vectorizer.transform([question_clean])
        
        # Predict
        intent = model.predict(question_vec)[0]
        proba = model.predict_proba(question_vec)[0]
        confidence = proba.max()
        
        # Get top 3 intents
        intent_indices = proba.argsort()[-3:][::-1]
        intent_classes = model.classes_
        top_intents = [
            {'intent': intent_classes[idx], 'confidence': float(proba[idx])}
            for idx in intent_indices
        ]
        
        result = {
            'intent': str(intent),
            'confidence': float(confidence),
            'top_intents': top_intents,
        }
        
        print(json.dumps(result))
    except Exception as e:
        print(json.dumps({'error': str(e), 'intent': 'unknown', 'confidence': 0.0}))
