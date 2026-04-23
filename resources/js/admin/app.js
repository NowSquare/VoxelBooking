/**
 * VoxelBooking Admin JavaScript
 *
 * Alpine.js (CSP build) — reactive state management without unsafe-eval
 * Lucide — icon rendering via data-lucide attribute (tree-shaken)
 *
 * Usage in templates:
 *   <i data-lucide="home"></i>
 *   <i data-lucide="settings" class="w-5 h-5"></i>
 *
 * Alpine CSP build rules:
 *   - No inline JS expressions in x-on, x-bind, x-show, etc.
 *   - All component logic must be registered via Alpine.data()
 *   - HTML references components by name: x-data="adminShell"
 *   - x-show can reference a property name directly
 *   - @click can reference a method name directly
 *
 * To add a new icon: import it below and add it to ICON_SET.
 *
 * Built by Vite, shipped as compiled JS.
 */

import Alpine from '@alpinejs/csp';
import { createIcons } from 'lucide';
import { initTooltips } from './tooltips.js';

// ── Icon Registry (tree-shaken) ──
// Only icons listed here are bundled. Add new icons as needed.
import {
    LayoutDashboard,
    Calendar,
    CalendarDays,
    CalendarCheck,
    User,
    Users,
    Settings,
    LogOut,
    ChevronDown,
    ChevronUp,
    ChevronRight,
    ChevronLeft,
    Plus,
    Search,
    Bell,
    Menu,
    X,
    Edit,
    Pencil,
    Trash2,
    Eye,
    EyeOff,
    Check,
    AlertCircle,
    AlertTriangle,
    Info,
    Shield,
    Key,
    Mail,
    Clock,
    Building2,
    UserPlus,
    FileText,
    Download,
    Upload,
    RefreshCw,
    MoreVertical,
    Minus,
    ExternalLink,
    Copy,
    Sun,
    Moon,
    Palette,
    Globe,
    Activity,
    TrendingUp,
    BarChart3,
    Hash,
    Bookmark,
    Briefcase,
    ShieldCheck,
    ScrollText,
    Server,
    Zap,
    HelpCircle,
    Lock,
    UserCog,
    Layers,
    Filter,
    Folder,
    Award,
    Archive,
    RotateCcw,
    CheckCircle,
    CalendarX,
    Contact,
    StickyNote,
    ArrowLeft,
    ArrowRight,
    List,
    Save,
    CalendarOff,
    PlusCircle,
    UserCheck,
    UserMinus,
    Ticket,
    Bed,
    Grid3X3,
    LayoutGrid,
    Play,
    ImagePlus,
    UserRound,
    Camera,
    Code2,
    MousePointerClick,
    Ban,
    CalendarClock,
    CalendarPlus,
    CalendarRange,
    Code,
    NotebookPen,
    Phone,
    SearchX,
    TrendingDown,
    Languages,
    Inbox,
    XCircle,
} from 'lucide';

const ICON_SET = {
    LayoutDashboard, Calendar, CalendarDays, CalendarCheck,
    User, Users, Settings, LogOut,
    ChevronDown, ChevronUp, ChevronRight, ChevronLeft, Plus, Search,
    Bell, Menu, X, Edit, Pencil, Trash2, Eye, EyeOff,
    Check, AlertCircle, AlertTriangle, Info, Shield, Key, Mail, Clock,
    Building2, UserPlus, FileText, Download, Upload,
    RefreshCw, MoreVertical, Minus, ExternalLink, Copy, Sun, Moon,
    Palette, Globe, Activity, TrendingUp, BarChart3, Hash,
    Bookmark, Briefcase, ShieldCheck, ScrollText, Server, Zap, HelpCircle, Lock,
    UserCog, Layers, Filter, Folder, Award, Archive, RotateCcw, CheckCircle,
    CalendarX, Contact, StickyNote, ArrowLeft, ArrowRight, List, Save,
    CalendarOff, PlusCircle, UserCheck, UserMinus,
    Ticket, Bed, Grid3X3, LayoutGrid, Play,
    ImagePlus, UserRound, Camera,
    Code2, MousePointerClick,
    Ban, CalendarClock, CalendarPlus, CalendarRange, Code,
    NotebookPen, Phone, SearchX, TrendingDown,
    Languages,
    Inbox, XCircle,
};

// ── Alpine: CSP-safe component registration ──
// All interactive admin components are registered here.
// Templates reference by name: x-data="adminShell"
//
// CSP build constraint: x-show and @click accept only property/method names,
// NOT arbitrary JS expressions. So we use x-show="sidebarOpen" (prop ref)
// and @click="toggleSidebar" (method ref).
// For sidebar CSS class toggling, we use $refs in the methods.

Alpine.data('adminShell', () => ({
    sidebarOpen: false,
    profileOpen: false,

    init() {
        // Close all on Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeSidebar();
                this.closeProfile();
            }
        });

        // Close profile on click outside
        document.addEventListener('click', (e) => {
            if (this.profileOpen && this.$refs.profileMenu &&
                !this.$refs.profileMenu.contains(e.target)) {
                this.closeProfile();
            }
        });
    },

    toggleSidebar() {
        this.sidebarOpen = !this.sidebarOpen;
        this.$refs.sidebar.classList.toggle('open', this.sidebarOpen);
    },

    closeSidebar() {
        this.sidebarOpen = false;
        this.$refs.sidebar.classList.remove('open');
    },

    toggleProfile() {
        this.profileOpen = !this.profileOpen;
    },

    closeProfile() {
        this.profileOpen = false;
    },

    toggleTheme() {
        const html = document.documentElement;
        const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('vb-theme', next);
    },

    switchTheme() {
        this.toggleTheme();
        this.closeProfile();
    },

    copyBookingUrl(event) {
        const btn = event.currentTarget;
        const url = btn.getAttribute('data-copy-url');
        if (!url) return;

        navigator.clipboard.writeText(url).then(() => {
            btn.classList.add('is-copied');
            setTimeout(() => btn.classList.remove('is-copied'), 1500);
        });
    },
}));

// ── Alpine: Slug Editor (live slug → URL preview) ──
Alpine.data('slugEditor', (initialSlug = '', baseUrl = '') => ({
    slug: initialSlug,
    baseUrl: baseUrl,

    get fullUrl() {
        return this.baseUrl + this.slug;
    },

    /** Normalize the slug field as the user types: lowercase, strip invalid chars. */
    normalize() {
        const input = this.$el.querySelector('#ts-slug');
        const cursor = input?.selectionStart ?? 0;
        const before = this.slug;
        this.slug = before.toLowerCase().replace(/[^a-z0-9\-]/g, '');
        // Restore cursor position if chars were stripped
        this.$nextTick(() => {
            if (input) {
                const diff = before.length - this.slug.length;
                input.setSelectionRange(cursor - diff, cursor - diff);
            }
        });
    },

    /** Copy the current live URL to clipboard. */
    copyUrl(event) {
        const btn = event.currentTarget;
        navigator.clipboard.writeText(this.fullUrl).then(() => {
            btn.classList.add('is-copied');
            setTimeout(() => btn.classList.remove('is-copied'), 1500);
        });
    },
}));

// ── Alpine: Slug Generator (auto-slug from name on create forms) ──
// Auto-generates a slug from the name field. Once the user manually edits the
// slug field, auto-generation stops (dirty flag). Normalizes to lowercase/hyphen-only.
Alpine.data('slugGenerator', () => ({
    slug: '',
    dirty: false,

    init() {
        // Restore old input (server validation round-trip)
        const slugInput = this.$refs.slugInput;
        if (slugInput && slugInput.value) {
            this.slug = slugInput.value;
            this.dirty = true; // User (or server) already provided a slug
        }
    },

    /** Called on name field input — auto-generate slug unless user has edited slug manually. */
    onNameInput(e) {
        if (this.dirty) return;
        const name = e.target.value || '';
        this.slug = name.toLowerCase().replace(/[^a-z0-9\s-]/g, '').replace(/[\s-]+/g, '-').replace(/^-|-$/g, '');
    },

    /** Called on slug field input — mark as dirty and normalize. */
    onSlugInput() {
        this.dirty = true;
        this.slug = this.slug.toLowerCase().replace(/[^a-z0-9-]/g, '');
    },

    /** Called on slug field blur — trim trailing hyphens. */
    onSlugBlur() {
        this.slug = this.slug.replace(/^-|-$/g, '');
    },
}));

// ── Alpine: Pattern Cards (CSP-safe radio card selection) ──

Alpine.data('patternCards', () => ({
    selected: 'timeslot',

    select(value) {
        this.selected = value;
    },

    isSelected(value) {
        return this.selected === value;
    },
}));

// ── Alpine: Color Sync (swatch ↔ text bidirectional sync) ──
Alpine.data('colorSync', () => ({
    hex: '#2563EB',

    init() {
        // Sync initial value from the color input if present
        if (this.$refs.colorPicker) {
            this.hex = this.$refs.colorPicker.value;
        }
    },

    onPickerChange() {
        this.hex = this.$refs.colorPicker.value;
        if (this.$refs.colorText) {
            this.$refs.colorText.value = this.hex;
        }
    },

    onTextChange() {
        const v = this.$refs.colorText.value;
        if (/^#[0-9a-fA-F]{6}$/.test(v)) {
            this.hex = v;
            if (this.$refs.colorPicker) {
                this.$refs.colorPicker.value = v;
            }
        }
    },
}));

// ── Alpine: Owner Setup (optional first-owner during tenant creation) ──
Alpine.data('ownerSetup', () => ({
    enabled: false,
    showPassword: false,
    smtpConfigured: false,
    sendEmail: false,

    init() {
        this.smtpConfigured = this.$el.dataset.smtpConfigured === '1';
        this.sendEmail = this.smtpConfigured;

        // Auto-expand if the form was repopulated with owner data
        if (this.$el.dataset.initiallyEnabled === '1') {
            this.enabled = true;
        }
    },

    // CSP-safe: referenced as @change="onToggleEnabled"
    onToggleEnabled() {
        this.enabled = !this.enabled;
        if (this.enabled) {
            this.$nextTick(() => {
                const tenantEmail = document.getElementById('tenant_email');
                const ownerEmail = this.$refs.ownerEmail;
                if (tenantEmail && ownerEmail && !ownerEmail.value) {
                    ownerEmail.value = tenantEmail.value;
                }
                this.generatePassword();
                if (window.refreshIcons) window.refreshIcons();
            });
        }
    },

    // CSP-safe: referenced as :value="ownerFormValue"
    get ownerFormValue() {
        return this.enabled ? '1' : '0';
    },

    // CSP-safe: referenced as :disabled="smtpNotConfigured"
    get smtpNotConfigured() {
        return !this.smtpConfigured;
    },

    // CSP-safe: referenced as @change="onToggleSendEmail"
    onToggleSendEmail() {
        this.sendEmail = !this.sendEmail;
    },

    generatePassword() {
        const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let pass = '';
        const arr = new Uint32Array(16);
        crypto.getRandomValues(arr);
        for (let i = 0; i < 16; i++) {
            pass += chars[arr[i] % chars.length];
        }
        if (this.$refs.ownerPassword) {
            this.$refs.ownerPassword.value = pass;
        }
    },

    togglePasswordVisibility() {
        this.showPassword = !this.showPassword;
        if (this.$refs.ownerPassword) {
            this.$refs.ownerPassword.type = this.showPassword ? 'text' : 'password';
        }
    },
}));

// ── Alpine: Invite User (business user invite form) ──
Alpine.data('inviteUser', () => ({
    role: 'owner',
    showPassword: false,
    smtpConfigured: false,
    sendEmail: false,

    init() {
        const initialRole = this.$el.dataset.initialRole;
        if (initialRole === 'owner' || initialRole === 'manager') {
            this.role = initialRole;
        }
        this.smtpConfigured = this.$el.dataset.smtpConfigured === '1';
        this.sendEmail = this.smtpConfigured;
        this.$nextTick(() => this.generatePassword());
    },

    // CSP-safe: property references for :class
    get isOwner() { return this.role === 'owner'; },
    get isManager() { return this.role === 'manager'; },
    get roleOwnerClass() { return this.role === 'owner' ? 'is-selected' : ''; },
    get roleManagerClass() { return this.role === 'manager' ? 'is-selected' : ''; },

    // CSP-safe: referenced as @change="selectOwner"
    selectOwner() { this.role = 'owner'; },
    selectManager() { this.role = 'manager'; },

    // CSP-safe: referenced as :disabled="smtpNotConfigured"
    get smtpNotConfigured() { return !this.smtpConfigured; },

    // CSP-safe: referenced as @change="onToggleSendEmail"
    onToggleSendEmail() { this.sendEmail = !this.sendEmail; },

    generatePassword() {
        const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let pass = '';
        const arr = new Uint32Array(16);
        crypto.getRandomValues(arr);
        for (let i = 0; i < 16; i++) {
            pass += chars[arr[i] % chars.length];
        }
        if (this.$refs.passwordField) {
            this.$refs.passwordField.value = pass;
        }
    },

    togglePasswordVisibility() {
        this.showPassword = !this.showPassword;
        if (this.$refs.passwordField) {
            this.$refs.passwordField.type = this.showPassword ? 'text' : 'password';
        }
    },
}));

// ── Alpine: Availability Grid (weekly hours editor) ──
Alpine.data('availabilityGrid', () => ({
    days: [],
    dayLabels: [],

    init() {
        const raw = this.$el.dataset.schedule;
        if (raw) {
            try { this.days = JSON.parse(raw); }
            catch { this.days = [[], [], [], [], [], [], []]; }
        } else {
            this.days = [[], [], [], [], [], [], []];
        }

        const labels = this.$el.dataset.dayLabels;
        if (labels) {
            try { this.dayLabels = JSON.parse(labels); }
            catch { this.dayLabels = []; }
        }
    },

    addWindow(day) {
        this.days[day].push({ start: '09:00', end: '17:00' });
        this.$nextTick(() => { if (window.refreshIcons) window.refreshIcons(); });
    },

    removeWindow(day, idx) {
        this.days[day].splice(idx, 1);
    },
}));

// ── Alpine: Blocked Date Scope (scope toggle for tenant vs staff) ──
Alpine.data('blockedDateScope', () => ({
    selectedScope: 'tenant',

    init() {
        const initial = this.$el.dataset.initialScope;
        if (initial) {
            this.selectedScope = initial;
        }
    },

    onScopeChange() {
        // Clear both entity selectors when switching scope
        const staffEl = document.getElementById('bd-staff');
        const resourceEl = document.getElementById('bd-resource');
        if (staffEl) staffEl.value = '';
        if (resourceEl) resourceEl.value = '';
    },
}));

// ── Alpine: Booking Create (manual admin booking form) ──
Alpine.data('bookingCreate', () => ({
    slug: '',
    locale: 'en',
    staffMap: {},
    allStaff: [],
    serviceId: '',
    staffId: '',
    date: '',
    time: '',
    slots: [],
    loadingSlots: false,

    get filteredStaff() {
        if (!this.serviceId) return this.allStaff;
        const linked = this.staffMap[this.serviceId];
        if (!Array.isArray(linked)) return [];
        return this.allStaff.filter(m => linked.includes(m.id));
    },

    init() {
        // Hydrate from data attributes
        const el = this.$el;
        this.slug = el.dataset.slug || '';
        this.locale = el.dataset.locale || 'en';

        try { this.staffMap = JSON.parse(el.dataset.staffMap || '{}'); }
        catch { this.staffMap = {}; }

        try { this.allStaff = JSON.parse(el.dataset.allStaff || '[]'); }
        catch { this.allStaff = []; }

        try {
            const old = JSON.parse(el.dataset.old || '{}');
            this.serviceId = old.service_id || '';
            this.staffId = old.staff_id || '';
            this.date = old.date || '';
            this.time = old.time || '';
        } catch { /* no old values */ }

        // Normalize stale staffId
        if (this.staffId && this.serviceId) {
            if (!this.filteredStaff.some(m => m.id === this.staffId)) {
                this.staffId = '';
            }
        }
        if (this.serviceId && this.date) {
            this.fetchSlots();
        }
    },

    formatTime(timeStr) {
        try {
            const [h, m] = timeStr.split(':').map(Number);
            const d = new Date(2000, 0, 1, h, m);
            return d.toLocaleTimeString(this.locale, { hour: '2-digit', minute: '2-digit' });
        } catch {
            return timeStr;
        }
    },

    async onServiceChange() {
        if (this.staffId && !this.filteredStaff.some(m => m.id === this.staffId)) {
            this.staffId = '';
        }
        this.time = '';
        this.slots = [];
        if (this.serviceId && this.date) {
            await this.fetchSlots();
        }
    },

    async onStaffChange() {
        this.time = '';
        this.slots = [];
        if (this.serviceId && this.date) {
            await this.fetchSlots();
        }
    },

    async onDateChange() {
        this.time = '';
        this.slots = [];
        if (this.serviceId && this.date) {
            await this.fetchSlots();
        }
    },

    async fetchSlots() {
        this.loadingSlots = true;
        this.slots = [];

        try {
            const params = new URLSearchParams({
                date: this.date,
                service_id: this.serviceId,
            });
            if (this.staffId) {
                params.set('staff_id', this.staffId);
            }

            const res = await fetch('/api/' + encodeURIComponent(this.slug) + '/availability?' + params);
            if (res.ok) {
                const data = await res.json();
                this.slots = (data.slots || []).map(slot => ({
                    ...slot,
                    label: this.formatTime(slot.time) + ' \u2013 ' + this.formatTime(slot.end_time),
                }));
            }
        } catch (e) {
            console.error('Failed to fetch availability:', e);
        } finally {
            this.loadingSlots = false;
        }
    },
}));


// ── Alpine: Image Upload (reusable upload primitive) ──
// CSP-safe component. Template sets data-preview and data-has-file attributes.
// Drag/drop uses native listeners registered in init() since CSP Alpine
// cannot pass $event to handler methods.
// Referenced as: x-data="imageUpload"
Alpine.data('imageUpload', () => ({
    preview: '',
    hasFile: false,
    removeExisting: false,
    dragover: false,

    init() {
        this.preview = this.$el.dataset.preview || '';
        this.hasFile = this.$el.dataset.hasFile === '1';

        // Native drag/drop listeners (CSP-safe — no inline $event needed)
        this.$el.addEventListener('dragover', (e) => {
            e.preventDefault();
            this.dragover = true;
        });
        this.$el.addEventListener('dragleave', (e) => {
            e.preventDefault();
            this.dragover = false;
        });
        this.$el.addEventListener('drop', (e) => {
            e.preventDefault();
            this.dragover = false;
            const file = e.dataTransfer?.files?.[0];
            if (file && file.type.startsWith('image/')) {
                if (this.$refs.fileInput) {
                    this.$refs.fileInput.files = e.dataTransfer.files;
                }
                this._readFile(file);
            }
        });
    },

    _readFile(file) {
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (e) => {
            this.preview = e.target.result;
            this.hasFile = true;
            this.removeExisting = false;
            // Re-render Lucide icons after Alpine x-if swaps in new nodes
            this.$nextTick(() => { if (window.refreshIcons) window.refreshIcons(); });
        };
        reader.readAsDataURL(file);
    },

    // CSP-safe: referenced as @change="onFileChange"
    onFileChange() {
        const file = this.$refs.fileInput?.files?.[0];
        this._readFile(file);
    },

    // CSP-safe: referenced as @click="remove"
    remove() {
        this.preview = '';
        this.hasFile = false;
        this.removeExisting = true;
        if (this.$refs.fileInput) {
            this.$refs.fileInput.value = '';
        }
        // Re-render Lucide icons after Alpine x-if swaps in empty-state nodes
        this.$nextTick(() => { if (window.refreshIcons) window.refreshIcons(); });
    },

    // CSP-safe getters: referenced as :src="previewSrc", :value="removeValue"
    get previewSrc() {
        return this.preview;
    },

    get removeValue() {
        return this.removeExisting ? '1' : '';
    },
}));

// ── Alpine: Table Search (CSP-safe clear button) ──
// Manages the search input clear button inside table toolbars.
// HTML: x-data="tableSearch" on the <form>, x-ref="searchInput" on <input>,
//       @click="clear" on the clear button, @input="onInput" on the input.
Alpine.data('tableSearch', () => ({
    hasValue: false,

    init() {
        const initial = this.$el.dataset.initial || '';
        this.hasValue = initial.length > 0;
    },

    // CSP-safe: referenced as @input="onInput"
    onInput() {
        const input = this.$refs.searchInput;
        this.hasValue = input && input.value.length > 0;
    },

    // CSP-safe: referenced as @click="clear"
    clear() {
        // Navigate to clean URL: form action + hidden fields (no search param)
        const form = this.$el;
        const action = form.getAttribute('action') || window.location.pathname;
        const params = new URLSearchParams();
        form.querySelectorAll('input[type="hidden"]').forEach(input => {
            if (input.name && input.value) {
                params.set(input.name, input.value);
            }
        });
        const qs = params.toString();
        window.location.href = action + (qs ? '?' + qs : '');
    },
}));

// ── Alpine: Form Submit Guard (prevents double-submission) ──
// Adds a loading spinner to the submit button and blocks re-submit.
// Usage: x-data="formSubmit" on <form>, @submit="onSubmit" on <form>,
//        :disabled="submitDisabled" :class="submitClass" on <button>.
Alpine.data('formSubmit', () => ({
    submitting: false,

    // CSP-safe: referenced as @submit="onSubmit"
    // Alpine CSP passes the native Event as the first argument.
    onSubmit(e) {
        if (this.submitting) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }
        this.submitting = true;
        // First submission: form submits normally via native behavior.
    },

    // CSP-safe: referenced as :disabled="submitDisabled"
    get submitDisabled() {
        return this.submitting;
    },

    // CSP-safe: referenced as :class="submitClass"
    get submitClass() {
        return this.submitting ? 'is-loading' : '';
    },
}));

// ── Alpine: start ──
window.Alpine = Alpine;
Alpine.start();

// ── Lucide: initial render ──
// Runs after Alpine has processed the DOM so x-if/x-for content is present.
createIcons({ icons: ICON_SET });

// ── Tooltips: replace native title= with styled tooltips ──
initTooltips();

// ── Refresh helper for Alpine-rendered content ──
window.refreshIcons = () => {
    createIcons({ icons: ICON_SET });
};

// ── Demo Mode: form submission guard ──
// When VB_DEMO flag is set by layout.php, intercept all POST form submissions
// and show a non-intrusive toast instead of allowing the write request.
if (window.VB_DEMO) {
    /**
     * Check if a form action URL is allowed to submit in demo mode.
     * Mirrors DemoMode::ALLOWED_WRITE_ROUTES on the server side.
     */
    function isDemoAllowed(action) {
        if (action.includes('/admin/login') || action.includes('/auth/logout')) return true;
        if (action.includes('/impersonate')) return true; // session-only, no DB writes
        return false;
    }

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.method.toUpperCase() !== 'POST') return;

        const action = form.getAttribute('action') || '';
        if (isDemoAllowed(action)) return;

        e.preventDefault();
        showDemoToast();
    });

    // Intercept delete buttons, status changes, and other action clicks
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('button[type="submit"], a[data-method="delete"]');
        if (!btn) return;

        const form = btn.closest('form');
        if (!form) return;
        const action = form.getAttribute('action') || '';
        if (isDemoAllowed(action)) return;

        if (form.method && form.method.toUpperCase() === 'POST') {
            e.preventDefault();
            e.stopPropagation();
            showDemoToast();
        }
    }, true);
}

function showDemoToast() {
    // Remove existing toast
    const existing = document.querySelector('.vb-demo-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = 'vb-demo-toast';
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>${document.querySelector('[data-demo-toast]')?.textContent ?? ''}</span>
    `;
    document.body.appendChild(toast);

    // Animate in
    requestAnimationFrame(() => {
        toast.classList.add('visible');
    });

    // Auto-dismiss after 3s
    setTimeout(() => {
        toast.classList.remove('visible');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Expose to global scope so inline onclick handlers in templates can call it.
// Module-scoped functions are not reachable from HTML attributes.
window.showDemoToast = showDemoToast;

// ── Global Confirm Dialog (vanilla JS, CSP-safe) ──
// Intercepts form submissions on forms with data-confirm="message" attribute.
// Uses a static modal element rendered in layout.php — no Alpine involvement.
(function () {
    let pendingForm = null;

    const overlay = document.getElementById('vb-confirm-overlay');
    if (!overlay) return; // guard: modal not in DOM

    const msgEl    = overlay.querySelector('[data-confirm-message]');
    const btnText  = overlay.querySelector('[data-confirm-btn-text]');
    const btnOk    = overlay.querySelector('[data-confirm-ok]');
    const btnCancel = overlay.querySelector('[data-confirm-cancel]');
    const btnClose  = overlay.querySelector('[data-confirm-close]');

    function open(form) {
        pendingForm = form;
        const message = form.getAttribute('data-confirm') || 'Are you sure?';
        const label   = form.getAttribute('data-confirm-text') || 'Confirm';

        if (msgEl)   msgEl.textContent = message;
        if (btnText) btnText.textContent = label;

        overlay.style.display = '';
        requestAnimationFrame(() => overlay.classList.add('is-visible'));
    }

    function close() {
        overlay.classList.remove('is-visible');
        setTimeout(() => { overlay.style.display = 'none'; }, 200);
        pendingForm = null;
    }

    function confirm() {
        if (pendingForm) {
            const form = pendingForm;
            pendingForm = null; // clear before submit to prevent re-intercept
            overlay.classList.remove('is-visible');
            setTimeout(() => { overlay.style.display = 'none'; }, 200);
            // Submit natively, bypassing this listener
            form.removeAttribute('data-confirm');
            form.requestSubmit();
        }
    }

    // Intercept form submissions with data-confirm
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!form.hasAttribute('data-confirm')) return;

        e.preventDefault();
        e.stopPropagation();
        open(form);
    }, true);

    // Button handlers
    if (btnOk)     btnOk.addEventListener('click', confirm);
    if (btnCancel) btnCancel.addEventListener('click', close);
    if (btnClose)  btnClose.addEventListener('click', close);

    // Close on overlay background click
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) close();
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && pendingForm) close();
    });
})();

// ── Clickable Rows (CSP-safe navigation) ──
// Rows with [data-href] navigate on click. Replaces inline onclick handlers.
document.addEventListener('click', (e) => {
    const row = e.target.closest('[data-href]');
    if (!row) return;
    // Don't hijack clicks on interactive children (links, buttons, inputs)
    if (e.target.closest('a, button, input, select, textarea, [role="button"]')) return;
    window.location = row.getAttribute('data-href');
});

// ── Select Navigation (CSP-safe) ──
// <select data-navigate-select data-navigate-base="/admin/tenants/{id}/foo"
//         data-navigate-default="/admin/tenants/{id}/bar">
// On change, navigates to base + "/" + value, or to default when value is empty.
document.addEventListener('change', (e) => {
    const sel = e.target.closest('[data-navigate-select]');
    if (!sel) return;
    const value = sel.value;
    const base = sel.getAttribute('data-navigate-base') || '';
    const defaultUrl = sel.getAttribute('data-navigate-default') || base;
    window.location.href = value ? (base + '/' + value) : defaultUrl;
});

// ── Textarea Autosize (.vb-textarea) ──
// Textareas with .vb-textarea automatically grow to fit content.
// overflow: hidden is set in CSS to prevent scrollbar flash.
function autosizeTextarea(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}

// Initialize on page load
document.querySelectorAll('.vb-textarea').forEach((ta) => {
    autosizeTextarea(ta);
    ta.addEventListener('input', () => autosizeTextarea(ta));
});

// ── Tab Overflow Detection (.vb-tabs) ──
// Adds 'is-scrollable' class when tab content overflows the container,
// enabling gradient fade masks. Also scrolls the active tab into view.
(function () {
    const tabNavs = document.querySelectorAll('.vb-tabs');
    if (!tabNavs.length) return;

    const check = () => {
        tabNavs.forEach((nav) => {
            nav.classList.toggle('is-scrollable', nav.scrollWidth > nav.clientWidth + 2);
        });
    };

    check();
    window.addEventListener('resize', check, { passive: true });

    // Scroll active tab into view on load
    tabNavs.forEach((nav) => {
        const active = nav.querySelector('.vb-tab.active');
        if (active) {
            active.scrollIntoView({ behavior: 'instant', block: 'nearest', inline: 'center' });
        }
    });
})();

// ── Platform-wide Form Validation ──
// Core logic lives in form-validator.js (also imported by tests).
// On submit: validates all visible inputs, marks invalid with .is-invalid,
// shows .vb-form-error below, focuses first error. Errors clear on edit.
import { clearFieldError, validateForm } from './form-validator.js';

(function initFormValidator() {
    // Intercept form submit — validate before sending
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        // Skip non-admin forms (login, booking, etc.) — only forms inside .vb-card
        if (!form.closest('.vb-card')) return;

        const firstInvalid = validateForm(form);

        if (firstInvalid) {
            e.preventDefault();
            e.stopPropagation();
            firstInvalid.focus();
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, true);

    // Clear error state on input/change (immediate feedback that the user is fixing it)
    document.addEventListener('input', (e) => {
        const el = e.target;
        if (el.classList && el.classList.contains('is-invalid')) {
            clearFieldError(el);
        }
    });
    document.addEventListener('change', (e) => {
        const el = e.target;
        if (el.classList && el.classList.contains('is-invalid')) {
            clearFieldError(el);
        }
    });
})();
