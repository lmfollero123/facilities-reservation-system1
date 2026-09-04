import { createRoot } from 'react-dom/client';
import BookingCalendar from './App.jsx';

const rootEl = document.getElementById('bcf-calendar-root');
if (rootEl) {
    const facilities = JSON.parse(rootEl.getAttribute('data-facilities') || '[]');
    const initialFacilityId = parseInt(rootEl.getAttribute('data-initial-facility-id') || '0', 10);
    const initialYear = parseInt(rootEl.getAttribute('data-initial-year') || '0', 10);
    const initialMonth = parseInt(rootEl.getAttribute('data-initial-month') || '0', 10);

    createRoot(rootEl).render(
        <BookingCalendar
            facilities={facilities}
            initialFacilityId={initialFacilityId}
            initialYear={initialYear}
            initialMonth={initialMonth}
        />
    );
}
