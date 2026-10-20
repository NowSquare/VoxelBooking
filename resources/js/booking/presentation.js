export function bookingPresentation({ config, t }) {
    return {
        finderOpen: false,
        finderAfter: '00:00',
        finderLoading: false,
        finderSearched: false,
        finderResults: [],
        finderError: '',
        _finderRequest: 0,

        get bookingObjectTitle() {
            if (this.step === 'reschedule-confirmed') {
                return this.rescheduleNewBooking?.event_name || this.managedBooking?.resource_name || this.managedBooking?.service_name || t('object.table_for', { count: this.managedBooking?.party_size });
            }
            if (this.step === 'confirmed' && this.booking) {
                const b = this.booking;
                return b.service || b.resource || b.event || b.event_name || t('object.table_for', { count: b.party_size || this.partySize });
            }
            if (this.manageMode) {
                const b = this.managedBooking;
                return b?.resource_name || b?.event_name || b?.service_name || t('object.reservation');
            }
            return this.selectedService?.name || this.selectedResource?.name || this.selectedEvent?.name ||
                (config.booking_pattern === 'capacity' ? t('object.table_for', { count: this.partySize }) : '');
        },
        get bookingObjectWhen() {
            if (config.booking_pattern === 'resource') {
                if (!this.checkInDate) return '';
                return this.formatDateDisplay(this.checkInDate) + (this.checkOutDate ? ' → ' + this.formatDateDisplay(this.checkOutDate) : '');
            }
            if (config.booking_pattern === 'capacity') return this.capacitySlotLabel;
            if (config.booking_pattern === 'event') {
                return this.selectedEvent ? this.formatEventDate(this.selectedEvent.start_datetime, this.selectedEvent.start_utc) + ' · ' + this.eventTimeSummary : '';
            }
            return this.selectedSlotLabel;
        },
        get bookingObjectMeta() {
            const bits = [];
            if (this.selectedService) {
                bits.push(this.formatDuration(this.selectedService.duration_minutes));
                if (this.selectedService.price != null) bits.push(this.selectedService.price_label || this.formatPrice(this.selectedService.price));
                if (this.selectedStaff) bits.push(this.selectedStaff.name);
                else if (this.staff.length > 1) bits.push(t('staff.any_available'));
            } else if (this.selectedResource) {
                bits.push(t('resource.capacity_label', { count: this.selectedResource.capacity }));
                if (this.resourceAvailability) bits.push(this.formatPrice(this.resourceAvailability.total));
                else if (this.selectedResource.price_per_night != null) bits.push(this.selectedResource.price_label || this.formatPrice(this.selectedResource.price_per_night) + t('resource.per_night'));
            } else if (this.selectedEvent) {
                bits.push(this.formatEventPrice(this.selectedEvent.price));
                if (['details', 'review'].includes(this.step)) bits.push(this.eventSpotCount + ' ' + t(this.eventSpotCount === 1 ? 'event.spot' : 'event.spots'));
            }
            return bits.filter(Boolean).join(' · ');
        },
        get showBookingContext() {
            return !!this.bookingObjectTitle && !this.manageMode && !['loading', 'load-error', 'empty', 'unsupported', 'service', 'resource', 'party-size', 'event-list', 'event-detail', 'review', 'confirmed'].includes(this.step);
        },
        get canEditChoice() {
            return !this.submitting && (config.booking_pattern === 'timeslot' ? this.services.length > 1 || this.staff.length > 1 :
                config.booking_pattern === 'resource' ? this.resources.length > 1 : true);
        },
        get canEditWhen() { return !this.submitting && !!this.bookingObjectWhen && config.booking_pattern !== 'event'; },
        get bookingObjectReviewRows() {
            const title = this.bookingObjectTitle;
            const contact = ['summary.contact_name', 'summary.contact_email', 'summary.contact_phone'].map(key => t(key));
            return this.orderBookingFacts(this.activeReviewRows.filter(row => row.value && row.value !== title && !contact.includes(row.label)));
        },
        get bookingObjectContactRows() {
            if (this.manageMode && this.managedBooking) {
                return [
                    { label: t('summary.contact_name'), value: this.managedBooking.customer_name },
                    { label: t('summary.contact_email'), value: this.managedBooking.customer_email },
                ].filter(row => row.value);
            }
            return [
                { label: t('summary.contact_name'), value: this.customerName },
                { label: t('summary.contact_email'), value: this.customerEmail },
                { label: t('summary.contact_phone'), value: this.customerPhone },
                { label: t('form.notes_label'), value: this.customerNotes },
            ].filter(row => row.value);
        },
        get bookingReference() { return this.booking ? t('confirmed.reference_label') + ' · ' + this.booking.id : ''; },
        get replacementBookingUrl() {
            return this.managedBooking?.status === 'rescheduled' && this.managedBooking.rescheduled_to_id ? this.manageUrl(this.managedBooking.rescheduled_to_id) : '';
        },
        get isEventWaitlistSelection() {
            return config.booking_pattern === 'event' && !!this.selectedEvent && this.selectedEvent.remaining < this.eventSpotCount;
        },
        get reviewSubmitLabel() {
            if (config.booking_pattern === 'event' && this.selectedEvent?.remaining < this.eventSpotCount) return t('event.join_waitlist');
            return config.requires_approval ? t('buttons.request') : t('buttons.confirm');
        },
        get bookingObjectConfirmRows() {
            const title = this.bookingObjectTitle;
            return this.orderBookingFacts(this.activeConfirmRows.filter(row => row.value && row.value !== title));
        },
        get bookingObjectManagedRows() {
            return this.orderBookingFacts(this.manageSummaryRows.filter(row => row.value && row.value !== this.bookingObjectTitle));
        },
        get rescheduleConfirmedObjectRows() {
            return this.orderBookingFacts(this.rescheduleConfirmedRows.filter(row => row.value && row.value !== this.bookingObjectTitle));
        },
        orderBookingFacts(rows) {
            const order = ['summary.date_label', 'summary.time_label', 'resource.check_in_label', 'resource.check_out_label', 'summary.with_label', 'summary.duration_label', 'resource.nights_label', 'resource.guests_label', 'timezone.label', 'summary.price_label', 'resource.total_label'].map(key => t(key));
            const priority = label => order.includes(label) ? order.indexOf(label) : order.length;
            return [...rows].sort((a, b) => priority(a.label) - priority(b.label));
        },
        editChoice() {
            if (this.submitting) return;
            this.cancelFinder();
            if (config.booking_pattern === 'timeslot') {
                if (this.services.length > 1) this.goToStep('service');
                else this.goToStep('staff');
            } else if (config.booking_pattern === 'resource') this.goToStep('resource');
            else if (config.booking_pattern === 'capacity') this.goToStep('party-size');
            else this.goToStep('event-list');
        },
        editWhen() {
            if (this.submitting) return;
            this.cancelFinder();
            if (config.booking_pattern === 'resource') this.goToStep('resource-date');
            else if (config.booking_pattern === 'capacity') this.goToStep('capacity-date');
            else this.goToStep('date');
        },
        editDetails() { if (!this.submitting) this.goToStep('details'); },
        toggleFinder() {
            this.finderOpen = !this.finderOpen;
            if (!this.finderOpen) this.cancelFinder();
        },
        cancelFinder() {
            this._finderRequest++;
            this.finderLoading = false;
            this.finderResults = [];
            this.finderSearched = false;
            this.finderError = '';
            this.slotAlternatives = [];
        },
        setFinderAfter(value) {
            this.finderAfter = value;
            this.cancelFinder();
            this.finderResults = [];
            this.finderSearched = false;
        },
        get finderTimeOptions() {
            return ['00:00', '09:00', '12:00', '17:00'].map(time => ({ value: time, label: time === '00:00' ? t('finder.any_time') : t('finder.after_time', { time: this.formatClock(time) }) }));
        },
        async findTimes() {
            const request = ++this._finderRequest;
            const service = this.selectedService?.id;
            const staff = this.selectedStaff?.id || '';
            if (!service) return;
            this.finderLoading = true;
            this.finderSearched = false;
            this.finderError = '';
            this.finderResults = [];
            // A deliberately bounded experiment: at most two month reads and
            // seven date reads, yielding three complete, selectable appointments.
            const today = this.tenantToday;
            const parts = today.split('-').map(Number);
            const dates = Array.from({ length: 7 }, (_, i) => new Date(Date.UTC(parts[0], parts[1] - 1, parts[2] + i)).toISOString().slice(0, 10));
            const months = [...new Set(dates.map(date => date.slice(0, 7)))];
            const current = () => request === this._finderRequest && service === this.selectedService?.id && staff === (this.selectedStaff?.id || '') && this.finderOpen;
            try {
                const available = new Set();
                for (const month of months) {
                    const [year, number] = month.split('-');
                    const params = new URLSearchParams({ service_id: service, staff_id: staff, year, month: Number(number) });
                    const data = await this.api('/available-dates?' + params);
                    if (!current()) return;
                    (data.dates || []).forEach(date => available.add(date));
                }
                const results = [];
                for (const date of dates.filter(date => available.has(date))) {
                    const params = new URLSearchParams({ service_id: service, staff_id: staff, date });
                    const data = await this.api('/availability?' + params);
                    if (!current()) return;
                    const slot = (data.slots || []).find(slot => {
                        const display = this.formatFinderDatetime(date, slot.time, slot.start_utc);
                        return display.time >= this.finderAfter;
                    });
                    if (slot) results.push({ date, slot, service, staff });
                    if (results.length === 3) break;
                }
                this.finderResults = results;
                this.finderSearched = true;
                this.announcement = t(results.length ? 'finder.results_ready' : 'finder.no_results');
            } catch (error) {
                if (current()) this.finderError = t('errors.connection');
            } finally {
                if (current()) {
                    this.finderLoading = false;
                    this.$nextTick(() => {
                        if (!current()) return;
                        const target = this.rootElement?.querySelector('.vb-book-finder-results button') || this.rootElement?.querySelector('.vb-book-finder-controls button');
                        target?.focus({ preventScroll: true });
                    });
                }
            }
        },
        finderResultLabel(result) {
            return this.formatDateDisplay(this.displayDate(result.date, result.slot.time, result.slot.start_utc)) + ' · ' + this.formatDisplayTime(result.slot.time, result.date, result.slot.start_utc);
        },
        chooseFinderResult(result) {
            if (result.service !== this.selectedService?.id || result.staff !== (this.selectedStaff?.id || '') || !this.finderResults.includes(result)) return;
            this.selectedDate = result.date;
            this.selectedSlot = result.slot;
            this.cancelFinder();
            this.goToStep('details');
        },
    };
}
