/**
 * Final-review card for chatbot-driven bookings.
 *
 * The assistant collects the details; this renders them for the resident to
 * check and only submits the booking once they press Confirm. Shared by the
 * full-screen assistant page and the floating widget so both behave the same.
 */
(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatDate(isoDate) {
        var parsed = new Date(isoDate + 'T00:00:00');
        if (isNaN(parsed.getTime())) {
            return isoDate;
        }
        return parsed.toLocaleDateString(undefined, {
            weekday: 'short', year: 'numeric', month: 'short', day: 'numeric'
        });
    }

    function buildCard(review) {
        var rows = [
            ['Facility', review.facility_name],
            ['Date', formatDate(review.reservation_date)],
            ['Time', review.time_slot],
            ['Purpose', review.purpose],
            ['Attendees', review.expected_attendees]
        ];

        var html = '<div class="frs-booking-review" data-frs-booking-review>' +
            '<p class="frs-booking-review__title">Final review</p>' +
            '<dl class="frs-booking-review__list">';
        rows.forEach(function (row) {
            html += '<div class="frs-booking-review__row">' +
                '<dt>' + escapeHtml(row[0]) + '</dt>' +
                '<dd>' + escapeHtml(row[1]) + '</dd>' +
                '</div>';
        });
        html += '</dl>';
        if (!review.is_free) {
            html += '<p class="frs-booking-review__note">This facility is not free — payment is required after approval.</p>';
        }
        html += '<div class="frs-booking-review__actions">' +
            '<button type="button" class="frs-booking-review__confirm" data-frs-confirm>Confirm booking</button>' +
            '<button type="button" class="frs-booking-review__cancel" data-frs-cancel>Cancel</button>' +
            '</div>' +
            '<p class="frs-booking-review__status" data-frs-status hidden></p>' +
            '</div>';

        return html;
    }

    /**
     * Render the review card into `container`.
     *
     * opts.endpoint  — URL the confirm POST goes to
     * opts.basePath  — app base path, for the "View reservation" link
     * opts.csrfToken — sent as X-CSRF-Token when present
     * opts.onSettled — called after a successful booking or a cancel
     */
    function renderReview(container, review, opts) {
        opts = opts || {};

        var wrapper = document.createElement('div');
        wrapper.innerHTML = buildCard(review);
        var card = wrapper.firstChild;
        container.appendChild(card);

        var confirmBtn = card.querySelector('[data-frs-confirm]');
        var cancelBtn = card.querySelector('[data-frs-cancel]');
        var status = card.querySelector('[data-frs-status]');

        function setStatus(text, isError) {
            status.textContent = text;
            status.hidden = false;
            card.classList.toggle('is-error', !!isError);
        }

        cancelBtn.addEventListener('click', function () {
            card.remove();
            if (typeof opts.onSettled === 'function') {
                opts.onSettled({ cancelled: true });
            }
        });

        confirmBtn.addEventListener('click', function () {
            confirmBtn.disabled = true;
            cancelBtn.disabled = true;
            setStatus('Submitting…', false);

            var body = new URLSearchParams();
            body.append('confirm_booking', '1');
            body.append('facility_id', String(review.facility_id));
            body.append('reservation_date', review.reservation_date);
            body.append('time_slot', review.time_slot);
            body.append('purpose', review.purpose);
            body.append('expected_attendees', String(review.expected_attendees));

            var headers = {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            };
            if (opts.csrfToken) {
                headers['X-CSRF-Token'] = opts.csrfToken;
            }

            fetch(opts.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers,
                body: body
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data || {} };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.data.reservation_id) {
                        // Re-enable so the resident can retry after fixing the cause
                        // (a slot taken in the meantime, a limit reached, and so on).
                        confirmBtn.disabled = false;
                        cancelBtn.disabled = false;
                        setStatus(result.data.reply || 'Could not complete the booking.', true);
                        return;
                    }

                    var link = (opts.basePath || '') + '/dashboard/reservation-detail?id=' + result.data.reservation_id;
                    card.querySelector('.frs-booking-review__actions').remove();
                    setStatus(result.data.reply || 'Booking submitted.', false);
                    card.classList.add('is-done');

                    var view = document.createElement('a');
                    view.className = 'frs-booking-review__link';
                    view.href = link;
                    view.textContent = 'View reservation';
                    card.appendChild(view);

                    if (typeof opts.onSettled === 'function') {
                        opts.onSettled({ reservationId: result.data.reservation_id, status: result.data.status });
                    }
                })
                .catch(function (error) {
                    console.error('Chatbot booking confirm failed:', error);
                    confirmBtn.disabled = false;
                    cancelBtn.disabled = false;
                    setStatus('Network error. Please try again.', true);
                });
        });

        return card;
    }

    /**
     * Turn the slots the assistant gathered into booking-form query params, for
     * the cases the chat cannot finish itself (anything needing an upload).
     */
    function prefillParams(data) {
        var params = new URLSearchParams();
        if (data.facility_id) params.set('facility_id', String(data.facility_id));
        if (data.reservation_date) params.set('reservation_date', data.reservation_date);
        var timeSlot = (data.start_time && data.end_time)
            ? (data.start_time + ' - ' + data.end_time)
            : (data.time_slot || '');
        if (timeSlot) params.set('time_slot', timeSlot);
        if (data.purpose) params.set('purpose', data.purpose);
        if (data.expected_attendees) params.set('expected_attendees', String(data.expected_attendees));
        return params;
    }

    window.frsChatbotBooking = {
        renderReview: renderReview,
        prefillParams: prefillParams
    };
})();
