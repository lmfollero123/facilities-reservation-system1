import { useState, useEffect, useCallback } from 'react';
import { motion, AnimatePresence } from 'motion/react';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

function basePath() {
    return (window.APP_BASE_PATH || '').replace(/\/$/, '');
}

function firstWeekdayOfMonth(year, month) {
    return new Date(year, month - 1, 1).getDay();
}

export default function BookingCalendar({ facilities, initialFacilityId, initialYear, initialMonth }) {
    const [facilityId, setFacilityId] = useState(initialFacilityId || 0);
    const [year, setYear] = useState(initialYear || new Date().getFullYear());
    const [month, setMonth] = useState(initialMonth || new Date().getMonth() + 1);
    const [days, setDays] = useState([]);
    const [loading, setLoading] = useState(false);

    const loadCalendar = useCallback(async (fid, y, m) => {
        if (!fid) {
            setDays([]);
            return;
        }
        setLoading(true);
        try {
            const url = basePath() + '/dashboard/book-facility-calendar-data'
                + '?facility_id=' + encodeURIComponent(fid)
                + '&year=' + encodeURIComponent(y)
                + '&month=' + encodeURIComponent(m);
            const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await res.json();
            setDays(Array.isArray(data.days) ? data.days : []);
        } catch (err) {
            console.error('book-facility-calendar-data fetch failed', err);
            setDays([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadCalendar(facilityId, year, month);
    }, [facilityId, year, month, loadCalendar]);

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        params.set('year', String(year));
        params.set('month', String(month));
        if (facilityId) {
            params.set('book_fac', String(facilityId));
        }
        window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
    }, [facilityId, year, month]);

    const [highlightedDates, setHighlightedDates] = useState(() => new Set());

    useEffect(() => {
        window.bcfCalendarGetState = () => ({ year, month, facilityId });
    }, [year, month, facilityId]);

    useEffect(() => {
        window.bcfCalendarSetHighlights = (isoDates) => {
            setHighlightedDates(new Set(Array.isArray(isoDates) ? isoDates : []));
        };
        return () => {
            delete window.bcfCalendarSetHighlights;
        };
    }, []);

    const monthLabel = MONTH_NAMES[month - 1] + ' ' + year;
    const leadingBlanks = facilityId ? firstWeekdayOfMonth(year, month) : 0;
    const yearOptions = [0, 1, 2].map((offset) => new Date().getFullYear() + offset);

    return (
        <div className="bcf-cal-toolbar-wrap">
            <div className="bcf-cal-month-heading">{monthLabel}</div>
            <div className="bcf-cal-toolbar-grid">
                <div className="bcf-cal-fac-field">
                    <label className="bcf-cal-fac-field-label" htmlFor="book-fac-cal-select">Facility</label>
                    <div className="bcf-cal-shell">
                        <i className="bi bi-building" aria-hidden="true"></i>
                        <select
                            id="book-fac-cal-select"
                            className="bcf-cal-fac-select"
                            aria-label="Choose facility for calendar"
                            value={facilityId}
                            onChange={(e) => setFacilityId(parseInt(e.target.value, 10))}
                        >
                            <option value="0">Choose a facility…</option>
                            {facilities.map((f) => (
                                <option key={f.id} value={f.id}>{f.name}</option>
                            ))}
                        </select>
                    </div>
                </div>
                <div className="bcf-cal-nav-cluster">
                    <select
                        className="bcf-cal-month-select"
                        aria-label="Select month"
                        value={month}
                        onChange={(e) => setMonth(parseInt(e.target.value, 10))}
                    >
                        {MONTH_NAMES.map((name, idx) => (
                            <option key={name} value={idx + 1}>{name}</option>
                        ))}
                    </select>
                    <select
                        className="bcf-cal-year-select"
                        aria-label="Select year"
                        value={year}
                        onChange={(e) => setYear(parseInt(e.target.value, 10))}
                    >
                        {yearOptions.map((y) => (
                            <option key={y} value={y}>{y}</option>
                        ))}
                    </select>
                    <button
                        type="button"
                        className="btn-outline bcf-cal-nav-btn"
                        onClick={() => {
                            const now = new Date();
                            setYear(now.getFullYear());
                            setMonth(now.getMonth() + 1);
                        }}
                    >
                        Today
                    </button>
                </div>
            </div>

            <div className="my-reservations-calendar" style={{ minHeight: 'auto' }}>
                <div className="my-reservations-calendar-grid">
                    {WEEKDAYS.map((w) => (
                        <div key={w} className="my-reservations-calendar-dayname">{w}</div>
                    ))}
                    {Array.from({ length: leadingBlanks }, (_, i) => (
                        <div key={'blank-' + i} className="my-reservations-calendar-cell empty"></div>
                    ))}
                    <AnimatePresence mode="popLayout">
                        {days.map((entry) => (
                            <CalendarCell
                                key={entry.date}
                                entry={entry}
                                facilityId={facilityId}
                                highlighted={highlightedDates.has(entry.date)}
                            />
                        ))}
                    </AnimatePresence>
                </div>
            </div>
            {loading && <div className="bcf-cal-loading" aria-live="polite">Loading availability…</div>}
        </div>
    );
}

function CalendarCell({ entry, facilityId, highlighted }) {
    function handleActivate() {
        if (!entry.is_pickable) return;
        if (typeof window.bcfCalendarActivateDate === 'function') {
            window.bcfCalendarActivateDate(entry.date, facilityId);
        }
    }
    const cls = [
        'my-reservations-calendar-cell',
        entry.is_today ? 'today' : '',
        !entry.is_pickable ? 'empty' : '',
        entry.status_class || '',
        entry.is_pickable ? 'bcf-book-cal-cell' : '',
        highlighted ? 'bcf-ai-suggest-date' : '',
    ].filter(Boolean).join(' ');

    const demandClass = entry.demand_classification
        ? 'demand-' + entry.demand_classification.toLowerCase().replace(/\s+/g, '-')
        : '';

    return (
        <motion.div
            initial={{ opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.18 }}
            className={cls}
            data-cal-date={entry.date}
            {...(entry.is_pickable ? {
                role: 'button',
                tabIndex: 0,
                'data-bcf-date': entry.date,
                onClick: handleActivate,
                onKeyDown: (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        handleActivate();
                    }
                },
            } : {})}
        >
            <div className="date-label">{entry.day}</div>
            {entry.chip_label && (
                <div className="status-chip" title={entry.chip_label}>{entry.chip_label}</div>
            )}
            {entry.holiday_name && (
                <div className="holiday-indicator" title={entry.holiday_name + ' (' + entry.holiday_type + ')'}>
                    <i className="bi bi-calendar-event"></i>
                </div>
            )}
            {entry.demand_classification && (
                <div className={'demand-strip ' + demandClass} title={'Demand: ' + entry.demand_classification}>
                    <span className="demand-score">{entry.demand_classification}</span>
                </div>
            )}
        </motion.div>
    );
}
