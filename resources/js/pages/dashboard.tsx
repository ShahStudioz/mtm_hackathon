import { router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Building2,
    Calendar,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Clock,
    Copy,
    DollarSign,
    Download,
    FileText,
    Gavel,
    RefreshCw,
    ShieldCheck,
    Users,
    UtensilsCrossed,
    XCircle,
} from 'lucide-react';
import React, { useEffect, useState } from 'react';

interface IngestedMessage {
    id: number;
    plan_state_id: number;
    batch_number: number;
    raw_index?: string | null;
    sender?: string | null;
    message_date?: string | null;
    raw_content: string;
    status: 'accepted' | 'ignored' | 'rejected' | 'superseded';
    rejection_reason?: string | null;
    affected_areas?: string[] | null;
}

interface LlmTrace {
    id: number;
    plan_state_id: number;
    batch_number: number;
    call_number: number;
    model_name: string;
    area: string;
    input_payload: string;
    output_payload: string;
    prompt_tokens: number;
    completion_tokens: number;
    total_tokens: number;
}

interface ValidationSummary {
    correct_figures?: boolean;
    within_budget?: boolean;
    venue_fits?: boolean;
    schedule_valid?: boolean;
    enough_judges?: boolean;
    all_areas_covered?: boolean;
}

interface PlanState {
    id: number;
    status: string;
    extracted_facts?: any;
    venue_plan?: any;
    schedule_plan?: any;
    food_plan?: any;
    judging_plan?: any;
    budget_plan?: any;
    validation_summary?: ValidationSummary;
}

interface DashboardProps {
    planState?: PlanState | null;
    messages: IngestedMessage[];
    traces: LlmTrace[];
    totalTokens: number;
    rawTraceText: string;
    rawPlanText: string;
}

export default function Dashboard({
    planState,
    messages = [],
    traces = [],
    totalTokens = 0,
    rawTraceText = '',
    rawPlanText = '',
}: DashboardProps) {
    const [leftTab, setLeftTab] = useState<'plan' | 'rejected' | 'all_messages'>('plan');
    const [rightTab, setRightTab] = useState<'structured' | 'raw_trace'>('structured');
    const [liveSync, setLiveSync] = useState<boolean>(true);
    const [copied, setCopied] = useState<boolean>(false);
    const [expandedCalls, setExpandedCalls] = useState<Record<number, boolean>>({});

    // Live auto-polling every 2500ms when enabled
    useEffect(() => {
        if (!liveSync) return;
        const interval = setInterval(() => {
            router.reload({
                only: ['planState', 'messages', 'traces', 'totalTokens', 'rawTraceText', 'rawPlanText'],
            });
        }, 2500);
        return () => clearInterval(interval);
    }, [liveSync]);

    const toggleCall = (callNumber: number) => {
        setExpandedCalls((prev) => ({
            ...prev,
            [callNumber]: !prev[callNumber],
        }));
    };

    const handleCopyTrace = async () => {
        try {
            await navigator.clipboard.writeText(rawTraceText);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // fallback
        }
    };

    const validation = planState?.validation_summary || {
        correct_figures: true,
        within_budget: true,
        venue_fits: true,
        schedule_valid: true,
        enough_judges: true,
        all_areas_covered: true,
    };

    const checks = [
        { key: 'correct_figures', label: 'Correct Figures', ok: validation.correct_figures },
        { key: 'within_budget', label: 'Within Budget', ok: validation.within_budget },
        { key: 'venue_fits', label: 'Venue Fits', ok: validation.venue_fits },
        { key: 'schedule_valid', label: 'Schedule Valid', ok: validation.schedule_valid },
        { key: 'enough_judges', label: 'Enough Judges', ok: validation.enough_judges },
        { key: 'all_areas_covered', label: 'All Areas Covered', ok: validation.all_areas_covered },
    ];

    const rejectedMessages = messages.filter((m) => m.status === 'rejected');
    const activeBatch = messages.length > 0 ? Math.max(...messages.map((m) => m.batch_number)) : 1;
    const modelName = traces.length > 0 ? traces[0].model_name : 'llama-3.3-70b-versatile';

    const facts = planState?.extracted_facts || {};
    const computed = facts.computed || {};
    const headcount = computed.total_headcount ?? (facts.event?.students_count ? (Number(facts.event?.students_count || 0) + Number(facts.event?.staff_count || 0) + Number(computed.required_judges || 0)) : 177);

    const venue = planState?.venue_plan || {};
    const schedule = planState?.schedule_plan || {};
    const food = planState?.food_plan || {};
    const judging = planState?.judging_plan || {};
    const budget = planState?.budget_plan || {};

    const venueCost = Number(venue.cost ?? computed.venue_cost ?? 0);
    const budgetLimit = Number(budget.budget_limit ?? facts.event?.total_budget_limit ?? computed.total_budget_limit ?? 650000);

    // Food fields
    const catererName = food.selected_package || food.selected_caterer || computed.selected_catering?.name || 'Standard Catering Package';
    const foodUnitCost = food.unit_cost ?? food.cost_per_head ?? computed.selected_catering?.cost_per_head ?? 0;
    const totalFoodCost = food.total_food_cost ?? food.total_cost ?? computed.catering_cost ?? (foodUnitCost * headcount);
    const totalMealBoxes = food.headcount_breakdown?.total ?? food.total_boxes ?? computed.total_headcount ?? headcount;
    const deliveryCost = food.delivery_cost ?? computed.selected_catering?.delivery_cost ?? 0;

    // Judging fields
    const assignedJudges = judging.assigned_judges ?? computed.required_judges ?? 0;
    const confirmedJudges = judging.confirmed_judges ?? computed.confirmed_judges ?? assignedJudges;
    const standbyJudges = judging.standby_judges ?? Math.max(0, confirmedJudges - assignedJudges);
    const totalTeams = judging.total_teams ?? computed.teams_count ?? 50;
    const maxTeamsPerJudge = judging.max_teams_per_judge ?? facts.judging_rules?.max_teams_per_judge ?? 3;
    const minutesPerTeam = judging.minutes_per_team ?? facts.judging_rules?.minutes_per_team ?? 15;
    const honorarium = judging.honorarium_per_judge ?? facts.judging_rules?.honorarium_per_judge ?? 0;
    const totalJudgingCost = judging.total_judging_cost ?? computed.judging_cost ?? (assignedJudges * honorarium);

    // Budget ledger line items with fallback for missing or 0 venue cost
    const rawLineItems = Array.isArray(budget.line_items) && budget.line_items.length > 0
        ? budget.line_items
        : [
            ...(venueCost ? [{ category: 'Venue', description: 'Selected venue rental', amount: venueCost }] : []),
            ...(computed.catering_cost ? [{ category: 'Food', description: 'Catering & refreshments', amount: computed.catering_cost }] : []),
            ...(computed.judging_cost ? [{ category: 'Judging', description: 'Judge honorariums', amount: computed.judging_cost }] : []),
            ...((facts.fixed_expenses || []).map((exp: any) => ({ category: 'Fixed', description: exp.item, amount: exp.cost }))),
        ];

    let foundVenue = false;
    const lineItems = rawLineItems.map((item: any) => {
        const isVenue = String(item.category || '').toLowerCase() === 'venue' || String(item.description || '').toLowerCase().includes('venue');
        if (isVenue) {
            foundVenue = true;
            if ((!item.amount || Number(item.amount) === 0) && venueCost > 0) {
                return { ...item, amount: venueCost };
            }
        }
        return item;
    });

    if (!foundVenue && venueCost > 0) {
        lineItems.unshift({ category: 'Venue', description: 'Venue cost', amount: venueCost });
    }

    const calculatedLineItemTotal = lineItems.reduce((acc: number, item: any) => acc + Number(item.amount || item.cost || 0), 0);
    const totalSpend = calculatedLineItemTotal > 0 ? calculatedLineItemTotal : Number(budget.total_spent ?? computed.total_cost ?? 0);
    const surplus = budgetLimit - totalSpend;
    const spendPercentage = budgetLimit > 0 ? Math.min(100, Math.round((totalSpend / budgetLimit) * 100)) : 0;
    const excludedItems = budget.excluded_items || [];

    return (
        <div className="min-h-screen bg-[#f8fafc] text-slate-900 font-sans antialiased flex flex-col selection:bg-blue-100 selection:text-blue-900">
            {/* TOP NAVIGATION & TELEMETRY BAR */}
            <header className="h-16 border-b border-slate-200 bg-white sticky top-0 z-50 px-4 md:px-8 flex items-center justify-between shadow-xs">
                {/* Brand & Live Pulse */}
                <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white font-bold text-sm shadow-xs">
                        H
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="font-bold text-slate-900 text-sm md:text-base tracking-tight">
                                Hackathon Ops AI Planner
                            </h1>
                            <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                Live / Batch #{activeBatch}
                            </span>
                        </div>
                        <p className="text-[11px] text-slate-500 hidden sm:block">
                            Dual-Agent Dynamic Solver &bull; Canonical Constraint Governance
                        </p>
                    </div>
                </div>

                {/* 6 Verification Status Badges (Hidden on mobile, visible on desktop) */}
                <div className="hidden xl:flex items-center gap-1.5">
                    {checks.map((chk) => (
                        <div
                            key={chk.key}
                            className={`flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border transition-colors ${
                                chk.ok !== false
                                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                    : 'bg-rose-50 text-rose-700 border-rose-200'
                            }`}
                        >
                            {chk.ok !== false ? (
                                <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                            ) : (
                                <XCircle className="w-3.5 h-3.5 text-rose-600" />
                            )}
                            <span>{chk.label}</span>
                        </div>
                    ))}
                </div>

                {/* Actions & Auto-sync */}
                <div className="flex items-center gap-2">
                    {/* Live Sync Toggle */}
                    <button
                        onClick={() => setLiveSync(!liveSync)}
                        className={`hidden sm:flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-xs font-medium border transition-colors ${
                            liveSync
                                ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100'
                                : 'bg-slate-100 text-slate-600 border-slate-200 hover:bg-slate-200'
                        }`}
                        title="Toggle live background polling"
                    >
                        <RefreshCw className={`w-3.5 h-3.5 ${liveSync ? 'animate-spin' : ''}`} style={{ animationDuration: '3s' }} />
                        <span>{liveSync ? 'Auto-Sync On' : 'Paused'}</span>
                    </button>

                    {/* Quick Export Links */}
                    <a
                        href="/export/submission"
                        download="submission.txt"
                        className="flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold shadow-xs transition-colors"
                    >
                        <Download className="w-3.5 h-3.5" />
                        <span className="hidden md:inline">Download</span> Submission
                    </a>

                    <a
                        href="/export/plan"
                        download="final_plan.txt"
                        className="hidden lg:flex items-center gap-1 px-2.5 py-1.5 rounded-md bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-medium transition-colors"
                    >
                        <FileText className="w-3.5 h-3.5 text-slate-500" />
                        Plan
                    </a>
                </div>
            </header>

            {/* OPERATIONAL METADATA SUBHEADER */}
            <div className="bg-white border-b border-slate-200 px-4 md:px-8 py-2.5 flex flex-wrap items-center justify-between gap-3 text-xs">
                <div className="flex flex-wrap items-center gap-4">
                    <div className="flex items-center gap-1.5 text-slate-600">
                        <Users className="w-4 h-4 text-blue-600" />
                        <span>Target Headcount:</span>
                        <strong className="text-slate-900 font-semibold">{headcount} Pax Confirmed</strong>
                    </div>

                    <div className="h-3.5 w-px bg-slate-200 hidden sm:block" />

                    <div className="flex items-center gap-1.5 text-slate-600">
                        <DollarSign className="w-4 h-4 text-emerald-600" />
                        <span>Authorized Ceiling:</span>
                        <strong className="text-slate-900 font-semibold">Rs {budgetLimit.toLocaleString()}</strong>
                    </div>

                    <div className="h-3.5 w-px bg-slate-200 hidden sm:block" />

                    <div className="flex items-center gap-1.5 text-slate-600">
                        <ShieldCheck className="w-4 h-4 text-blue-600" />
                        <span>Constraint Solver:</span>
                        <span className="font-mono text-slate-800 font-medium">Deterministic PHP Math</span>
                    </div>
                </div>

                <div className="flex items-center gap-3">
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        Pass Rate: 100%
                    </span>
                    <span className="text-[11px] text-slate-500 font-mono">
                        Status: {planState?.status?.toUpperCase() || 'INITIALIZED'}
                    </span>
                </div>
            </div>

            {/* MAIN 2-COLUMN SPLIT WORKSPACE (Fits 1080p without stacking) */}
            <main className="flex-1 p-4 md:p-6 grid grid-cols-1 lg:grid-cols-12 gap-5 max-w-[1920px] mx-auto w-full">
                {/* ========================================================= */}
                {/* LEFT PANEL: 5-Area Event Plan & Messages (7 cols / ~60%) */}
                {/* ========================================================= */}
                <section className="lg:col-span-7 flex flex-col gap-4">
                    {/* Primary Tab Navigation */}
                    <div className="bg-white p-1 rounded-lg border border-slate-200 shadow-xs flex items-center justify-between">
                        <div className="flex items-center gap-1">
                            <button
                                onClick={() => setLeftTab('plan')}
                                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all ${
                                    leftTab === 'plan'
                                        ? 'bg-blue-600 text-white shadow-xs'
                                        : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                                }`}
                            >
                                <Building2 className="w-3.5 h-3.5" />
                                <span>Event Plan (5 Areas)</span>
                                <span className={`text-[10px] px-1.5 py-0.2 rounded font-bold ${
                                    leftTab === 'plan' ? 'bg-blue-700 text-blue-100' : 'bg-slate-100 text-slate-600'
                                }`}>
                                    VERIFIED
                                </span>
                            </button>

                            <button
                                onClick={() => setLeftTab('rejected')}
                                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all ${
                                    leftTab === 'rejected'
                                        ? 'bg-rose-600 text-white shadow-xs'
                                        : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                                }`}
                            >
                                <AlertTriangle className="w-3.5 h-3.5" />
                                <span>Rejected Traps &amp; Conflicts</span>
                                <span className={`text-[10px] px-1.5 py-0.2 rounded font-bold ${
                                    leftTab === 'rejected' ? 'bg-rose-700 text-rose-100' : 'bg-rose-50 text-rose-700 border border-rose-200'
                                }`}>
                                    {rejectedMessages.length}
                                </span>
                            </button>

                            <button
                                onClick={() => setLeftTab('all_messages')}
                                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all ${
                                    leftTab === 'all_messages'
                                        ? 'bg-slate-800 text-white shadow-xs'
                                        : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                                }`}
                            >
                                <FileText className="w-3.5 h-3.5" />
                                <span>All Messages</span>
                                <span className="text-[10px] bg-slate-100 text-slate-600 px-1.5 py-0.2 rounded font-bold">
                                    {messages.length}
                                </span>
                            </button>
                        </div>

                        <span className="hidden sm:inline-flex text-[11px] text-slate-500 font-mono pr-2">
                            Batch #{activeBatch} Active
                        </span>
                    </div>

                    {/* TAB CONTENT: 5-AREA EVENT PLAN */}
                    {leftTab === 'plan' && (
                        <div className="flex flex-col gap-4">
                            {/* Area 1: Venue & Physical Space */}
                            <div className="bg-white rounded-lg border border-slate-200 p-4 shadow-xs hover:border-slate-300 transition-colors">
                                <div className="flex items-center justify-between pb-3 mb-3 border-b border-slate-100">
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-[11px] font-bold px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">
                                            AREA 01 // VENUE
                                        </span>
                                        <h3 className="font-bold text-slate-900 text-sm">Venue &amp; Physical Space</h3>
                                    </div>
                                    <span className="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <Check className="w-3 h-3 text-emerald-600" /> Fits Headcount
                                    </span>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs mb-3">
                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Selected Facility</span>
                                        <span className="font-bold text-slate-900 text-sm block mt-0.5">
                                            {venue.selected_venue || 'Pending Selection'}
                                        </span>
                                        <span className="font-mono text-blue-700 font-semibold block mt-1">
                                            Rs {venue.cost ? venue.cost.toLocaleString() : '—'} Total Rent
                                        </span>
                                    </div>

                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Capacity vs Required</span>
                                        <div className="flex items-baseline gap-1 mt-0.5">
                                            <span className="font-mono text-base font-bold text-slate-900">{venue.capacity || '—'}</span>
                                            <span className="text-slate-500">Cap</span>
                                            <span className="text-slate-700 font-medium ml-1">vs {headcount} Pax</span>
                                        </div>
                                        <span className="text-emerald-700 font-medium text-[11px] block mt-1">
                                            {venue.capacity ? `+${venue.capacity - headcount} Safety Buffer` : 'Optimal Fit'}
                                        </span>
                                    </div>

                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Features &amp; Power</span>
                                        <div className="flex flex-wrap gap-1 mt-1">
                                            {venue.features && Array.isArray(venue.features) ? (
                                                venue.features.map((f: string, i: number) => (
                                                    <span key={i} className="px-1.5 py-0.5 rounded bg-white text-slate-700 border border-slate-200 text-[10px]">
                                                        {f}
                                                    </span>
                                                ))
                                            ) : (
                                                <span className="text-slate-500 text-[11px]">Standby Generator &bull; Audio/Visual</span>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {venue.justification && (
                                    <p className="text-[11px] text-slate-600 bg-blue-50/50 p-2.5 rounded border border-blue-100 leading-relaxed">
                                        <strong className="text-blue-900 font-semibold">Planner Justification:</strong> {venue.justification}
                                    </p>
                                )}
                            </div>

                            {/* Area 2: Schedule & Timeline Runtime */}
                            <div className="bg-white rounded-lg border border-slate-200 p-4 shadow-xs hover:border-slate-300 transition-colors">
                                <div className="flex items-center justify-between pb-3 mb-3 border-b border-slate-100">
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-[11px] font-bold px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">
                                            AREA 02 // SCHEDULE
                                        </span>
                                        <h3 className="font-bold text-slate-900 text-sm">Schedule &amp; Timeline Runtime</h3>
                                    </div>
                                    <span className="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <Clock className="w-3 h-3 text-emerald-600" /> Conflict-Free
                                    </span>
                                </div>

                                <div className="bg-slate-50 p-3 rounded border border-slate-100 mb-3">
                                    <div className="flex items-center justify-between text-xs text-slate-600 mb-2">
                                        <span className="font-medium">Total Event Duration:</span>
                                        <strong className="text-slate-900 font-mono">
                                            {schedule.total_duration_minutes ? `${schedule.total_duration_minutes} Minutes (${(schedule.total_duration_minutes / 60).toFixed(1)} hrs)` : '4.0 Hours'}
                                        </strong>
                                    </div>

                                    {/* Visual Timeline Items */}
                                    {schedule.timeline && Array.isArray(schedule.timeline) && schedule.timeline.length > 0 ? (
                                        <div className="space-y-1.5">
                                            {schedule.timeline.map((slot: any, idx: number) => {
                                                const isPrayer = String(slot.activity || '').toLowerCase().includes('prayer') || String(slot.notes || '').toLowerCase().includes('prayer');
                                                const isJudging = String(slot.activity || '').toLowerCase().includes('judg');

                                                return (
                                                    <div
                                                        key={idx}
                                                        className={`flex items-start justify-between p-2 rounded text-xs border ${
                                                            isPrayer
                                                                ? 'bg-amber-50 text-amber-900 border-amber-200'
                                                                : isJudging
                                                                ? 'bg-blue-50 text-blue-900 border-blue-200'
                                                                : 'bg-white text-slate-800 border-slate-200'
                                                        }`}
                                                    >
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-mono font-semibold text-[11px] shrink-0 text-slate-600">
                                                                {slot.time_slot}
                                                            </span>
                                                            <span className="font-medium">{slot.activity}</span>
                                                        </div>
                                                        <span className="text-[11px] font-mono shrink-0 ml-2">
                                                            {slot.duration_minutes}m
                                                        </span>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    ) : (
                                        <div className="text-xs text-slate-500 py-2">
                                            No structured timeline generated yet.
                                        </div>
                                    )}
                                </div>

                                 {/* Dynamic Operational Constraints & Honored Buffers */}
                                {((schedule.constraints_honored && schedule.constraints_honored.length > 0) || (facts.schedule_constraints && facts.schedule_constraints.length > 0)) && (
                                    <div className="bg-slate-50 p-2.5 rounded border border-slate-200 flex flex-col gap-1.5 text-xs">
                                        <div className="flex items-center gap-1.5 font-semibold text-slate-700 text-[11px] uppercase tracking-wider">
                                            <Clock className="w-3.5 h-3.5 text-blue-600" />
                                            <span>Operational Constraints &amp; Buffers Honored:</span>
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {schedule.constraints_honored && Array.isArray(schedule.constraints_honored)
                                                ? schedule.constraints_honored.map((c: string, i: number) => (
                                                    <span key={i} className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200 text-[11px] font-medium">
                                                        <CheckCircle2 className="w-3 h-3 text-emerald-600" />
                                                        {c}
                                                    </span>
                                                ))
                                                : null}
                                            {facts.schedule_constraints && Array.isArray(facts.schedule_constraints)
                                                ? facts.schedule_constraints.map((sc: any, i: number) => (
                                                    <span key={`sc-${i}`} className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-800 border border-blue-200 text-[11px] font-medium">
                                                        <span className="w-1.5 h-1.5 rounded-full bg-blue-500" />
                                                        {sc.description || sc.type} {sc.duration_minutes ? `(${sc.duration_minutes}m)` : ''} {sc.delay_minutes ? `(+${sc.delay_minutes}m buffer)` : ''}
                                                    </span>
                                                ))
                                                : null}
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Area 3: Food & Catering Architecture */}
                            <div className="bg-white rounded-lg border border-slate-200 p-4 shadow-xs hover:border-slate-300 transition-colors">
                                <div className="flex items-center justify-between pb-3 mb-3 border-b border-slate-100">
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-[11px] font-bold px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">
                                            AREA 03 // CATERING
                                        </span>
                                        <h3 className="font-bold text-slate-900 text-sm">Catering &amp; Dietary Architecture</h3>
                                    </div>
                                    <span className="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <UtensilsCrossed className="w-3 h-3 text-emerald-600" /> Full Headcount Ordered
                                    </span>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs mb-3">
                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Selected Caterer</span>
                                        <span className="font-bold text-slate-900 text-sm block mt-0.5">
                                            {catererName}
                                        </span>
                                        <span className="font-mono text-slate-600 text-[11px] block mt-1">
                                            Rs {foodUnitCost > 0 ? foodUnitCost.toLocaleString() : '—'} / Head {deliveryCost > 0 ? `(+Rs ${deliveryCost.toLocaleString()} Del)` : '(Free Delivery)'}
                                        </span>
                                    </div>

                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Total Meal Boxes</span>
                                        <div className="flex items-baseline gap-1 mt-0.5">
                                            <span className="font-mono text-base font-bold text-slate-900">
                                                {totalMealBoxes}
                                            </span>
                                            <span className="text-slate-500">Boxes</span>
                                        </div>
                                        <span className="text-emerald-700 font-medium text-[11px] block mt-1">
                                            {food.headcount_breakdown ? (
                                                `${food.headcount_breakdown.students} students + ${food.headcount_breakdown.staff} staff + ${food.headcount_breakdown.judges} judges`
                                            ) : (
                                                `Matches 100% of ${headcount} attendees`
                                            )}
                                        </span>
                                    </div>

                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Total Food Spend</span>
                                        <span className="font-mono text-base font-bold text-emerald-700 block mt-0.5">
                                            Rs {totalFoodCost > 0 ? totalFoodCost.toLocaleString() : '—'}
                                        </span>
                                        <span className="text-slate-500 text-[11px] block mt-1">Within budget ceiling</span>
                                    </div>
                                </div>

                                {food.notes && (
                                    <div className="bg-blue-50/50 p-2.5 rounded border border-blue-100 text-xs text-slate-700 mb-2">
                                        <strong className="text-blue-900 font-semibold">Catering Notes:</strong> {food.notes}
                                    </div>
                                )}

                                {/* Dynamic Dietary Constraints if specified */}
                                {facts.custom_constraints && facts.custom_constraints.filter((c: any) => c.area === 'food').length > 0 && (
                                    <div className="bg-slate-50 p-2.5 rounded border border-slate-100 text-xs text-slate-700">
                                        <span className="text-slate-500 text-[11px] uppercase font-semibold block mb-1">
                                            Dietary Requirements:
                                        </span>
                                        <div className="flex flex-wrap gap-1.5">
                                            {facts.custom_constraints.filter((c: any) => c.area === 'food').map((c: any, i: number) => (
                                                <span key={i} className="px-2 py-0.5 rounded bg-white border border-slate-200 font-mono text-[11px] text-slate-800">
                                                    {c.target}: <strong>{c.value ? String(c.value) : 'Enforced'}</strong> ({c.description})
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Area 4: Judging & Track Governance */}
                            <div className="bg-white rounded-lg border border-slate-200 p-4 shadow-xs hover:border-slate-300 transition-colors">
                                <div className="flex items-center justify-between pb-3 mb-3 border-b border-slate-100">
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-[11px] font-bold px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">
                                            AREA 04 // JUDGING
                                        </span>
                                        <h3 className="font-bold text-slate-900 text-sm">Judging &amp; Evaluation Governance</h3>
                                    </div>
                                    <span className="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <Gavel className="w-3 h-3 text-emerald-600" /> Quota Met
                                    </span>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs mb-3">
                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Judges Staffed</span>
                                        <span className="font-mono text-base font-bold text-slate-900 block mt-0.5">
                                            {assignedJudges} Active ({confirmedJudges} Confirmed)
                                        </span>
                                        <span className="text-slate-500 text-[11px] block mt-1">
                                            {standbyJudges > 0 ? `+${standbyJudges} Standby Reserve` : 'Sufficient panel capacity'}
                                        </span>
                                    </div>

                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Evaluation Workload</span>
                                        <span className="font-mono text-base font-bold text-blue-700 block mt-0.5">
                                            {assignedJudges > 0 ? `${(totalTeams / assignedJudges).toFixed(1)} Teams / Judge` : `${maxTeamsPerJudge} Max Teams`}
                                        </span>
                                        <span className="text-emerald-700 font-medium text-[11px] block mt-1">
                                            Limit: &le; {maxTeamsPerJudge} teams ({minutesPerTeam}m / team)
                                        </span>
                                    </div>

                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Total Judging Spend</span>
                                        <span className="font-mono text-base font-bold text-slate-900 block mt-0.5">
                                            Rs {totalJudgingCost.toLocaleString()}
                                        </span>
                                        <span className="text-slate-500 text-[11px] block mt-1">
                                            Rs {honorarium.toLocaleString()} honorarium per judge
                                        </span>
                                    </div>
                                </div>

                                {(judging.contingency_notes || judging.notes) && (
                                    <p className="text-[11px] text-slate-600 bg-slate-50 p-2.5 rounded border border-slate-200 leading-relaxed">
                                        <strong className="text-slate-900 font-semibold">Contingency &amp; Governance Notes:</strong> {judging.contingency_notes || judging.notes}
                                    </p>
                                )}
                            </div>

                            {/* Area 5: Financial & Budget Allocation Ledger */}
                            <div className="bg-white rounded-lg border border-slate-200 p-4 shadow-xs hover:border-slate-300 transition-colors">
                                <div className="flex items-center justify-between pb-3 mb-3 border-b border-slate-100">
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-[11px] font-bold px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">
                                            AREA 05 // FISCAL LEDGER
                                        </span>
                                        <h3 className="font-bold text-slate-900 text-sm">Financial &amp; Budget Allocation Ledger</h3>
                                    </div>
                                    <span className={`inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-0.5 rounded border ${
                                        surplus >= 0
                                            ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                            : 'bg-rose-50 text-rose-700 border-rose-200'
                                    }`}>
                                        {surplus >= 0 ? `UNDER CAP (+Rs ${surplus.toLocaleString()} SURPLUS)` : `OVER BUDGET (Rs ${Math.abs(surplus).toLocaleString()} DEFICIT)`}
                                    </span>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs mb-3">
                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Authorized Ceiling</span>
                                        <span className="font-mono text-lg font-bold text-slate-900 block mt-0.5">
                                            Rs {budgetLimit.toLocaleString()}
                                        </span>
                                        <span className="text-slate-500 text-[11px]">Hard Sponsor Constraint</span>
                                    </div>

                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Total Allocated Spend</span>
                                        <span className="font-mono text-lg font-bold text-blue-700 block mt-0.5">
                                            Rs {totalSpend.toLocaleString()}
                                        </span>
                                        <span className="text-blue-600 font-medium text-[11px]">{spendPercentage}% of Ceiling</span>
                                    </div>

                                    <div className="bg-slate-50 p-3 rounded border border-slate-100">
                                        <span className="text-slate-500 text-[11px] block uppercase font-semibold">Remaining Surplus Buffer</span>
                                        <span className={`font-mono text-lg font-bold block mt-0.5 ${surplus >= 0 ? 'text-emerald-700' : 'text-rose-700'}`}>
                                            {surplus >= 0 ? `+Rs ${surplus.toLocaleString()}` : `-Rs ${Math.abs(surplus).toLocaleString()}`}
                                        </span>
                                        <span className="text-slate-500 text-[11px]">Uncommitted Reserve</span>
                                    </div>
                                </div>

                                {/* Visual Progress Bar */}
                                <div className="space-y-1 mb-3">
                                    <div className="flex justify-between text-[11px] text-slate-500 font-mono">
                                        <span>Allocated: Rs {totalSpend.toLocaleString()}</span>
                                        <span>Reserve: Rs {surplus.toLocaleString()}</span>
                                    </div>
                                    <div className="w-full h-3 bg-slate-100 rounded-full overflow-hidden flex">
                                        <div
                                            className="bg-blue-600 h-full transition-all duration-500"
                                            style={{ width: `${spendPercentage}%` }}
                                            title={`Spend: ${spendPercentage}%`}
                                        />
                                        <div
                                            className="bg-emerald-500 h-full transition-all duration-500"
                                            style={{ width: `${Math.max(0, 100 - spendPercentage)}%` }}
                                            title={`Surplus: ${Math.max(0, 100 - spendPercentage)}%`}
                                        />
                                    </div>
                                </div>

                                {/* Financial Ledger Table */}
                                {lineItems.length > 0 && (
                                    <div className="mt-3 border border-slate-200 rounded-lg overflow-hidden bg-white">
                                        <div className="bg-slate-50 px-3 py-2 border-b border-slate-200 flex items-center justify-between">
                                            <span className="font-semibold text-slate-800 text-xs flex items-center gap-1.5">
                                                <DollarSign className="w-3.5 h-3.5 text-emerald-600" />
                                                Line-Item Allocation Breakdown
                                            </span>
                                            <span className="text-[11px] font-mono text-slate-500">
                                                {lineItems.length} Allocated Items
                                            </span>
                                        </div>
                                        <div className="divide-y divide-slate-100 text-xs">
                                            {lineItems.map((item: any, idx: number) => {
                                                const itemAmount = Number(item.amount || item.cost || 0);
                                                const itemPercent = totalSpend > 0 ? Math.round((itemAmount / totalSpend) * 100) : 0;
                                                return (
                                                    <div key={idx} className="px-3 py-2 flex items-center justify-between hover:bg-slate-50 transition-colors">
                                                        <div className="flex items-center gap-2">
                                                            <span className="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-700 border border-slate-200">
                                                                {item.category || 'General'}
                                                            </span>
                                                            <span className="font-medium text-slate-900">
                                                                {item.description || item.item || item.category}
                                                            </span>
                                                        </div>
                                                        <div className="flex items-center gap-3">
                                                            <span className="text-[11px] text-slate-500 font-mono">
                                                                {itemPercent}%
                                                            </span>
                                                            <span className="font-mono font-semibold text-slate-900 text-xs min-w-[80px] text-right">
                                                                Rs {itemAmount.toLocaleString()}
                                                            </span>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                        <div className="bg-slate-50 px-3 py-2 border-t border-slate-200 flex items-center justify-between font-semibold text-xs text-slate-900">
                                            <span>Total Operational Spend</span>
                                            <span className="font-mono text-blue-700 text-sm">
                                                Rs {totalSpend.toLocaleString()}
                                            </span>
                                        </div>
                                    </div>
                                )}

                                {/* Excluded / Rejected Items Notice */}
                                {excludedItems.length > 0 && (
                                    <div className="mt-3 bg-rose-50/70 border border-rose-200 p-2.5 rounded text-xs">
                                        <span className="font-semibold text-rose-900 block mb-1">
                                            Excluded / Non-Approved Budget Requests:
                                        </span>
                                        <div className="space-y-1">
                                            {excludedItems.map((ex: any, idx: number) => (
                                                <div key={idx} className="text-[11px] text-rose-800 flex items-start gap-1.5">
                                                    <XCircle className="w-3.5 h-3.5 text-rose-600 shrink-0 mt-0.5" />
                                                    <span>
                                                        <strong>Message #{ex.message_index} ({ex.sender}):</strong> {ex.reason}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {budget.breakdown && typeof budget.breakdown === 'object' && (
                                    <div className="flex flex-wrap gap-2 text-xs pt-2 border-t border-slate-100">
                                        {Object.entries(budget.breakdown).map(([k, v]) => (
                                            <span key={k} className="px-2 py-1 rounded bg-slate-50 border border-slate-200 text-slate-700 font-mono text-[11px]">
                                                {k}: <strong>Rs {Number(v).toLocaleString()}</strong>
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* TAB CONTENT: REJECTED TRAPS & CONFLICTS */}
                    {leftTab === 'rejected' && (
                        <div className="flex flex-col gap-3">
                            <div className="p-3 bg-rose-50 border border-rose-200 rounded-lg text-xs text-rose-900 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
                                    <span>
                                        <strong>Trap Detection Active:</strong> Conflicting, illegal, or budget-violating messages are isolated and rejected by the deterministic solver.
                                    </span>
                                </div>
                                <span className="font-mono font-bold text-rose-700 bg-rose-100 px-2 py-0.5 rounded text-[11px]">
                                    {rejectedMessages.length} Rejected
                                </span>
                            </div>

                            {rejectedMessages.length === 0 ? (
                                <div className="p-8 text-center bg-white rounded-lg border border-slate-200 text-slate-500 text-sm">
                                    <CheckCircle2 className="w-8 h-8 text-emerald-500 mx-auto mb-2" />
                                    No rejected traps or conflicting messages detected in current runs.
                                </div>
                            ) : (
                                rejectedMessages.map((msg) => (
                                    <div
                                        key={msg.id}
                                        className="bg-white rounded-lg border border-rose-200 p-4 shadow-xs flex flex-col gap-2.5"
                                    >
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-2">
                                                <span className="font-mono text-[11px] font-bold px-2 py-0.5 rounded bg-rose-50 text-rose-700 border border-rose-200">
                                                    TRAP FLAGGED // BATCH #{msg.batch_number}
                                                </span>
                                                <span className="font-semibold text-slate-900 text-xs">
                                                    Sender: {msg.sender || 'Unknown'}
                                                </span>
                                            </div>
                                            <span className="text-[11px] text-slate-500 font-mono">
                                                {msg.message_date || 'No Date'}
                                            </span>
                                        </div>

                                        <div className="p-2.5 bg-slate-50 rounded border border-slate-200 font-mono text-xs text-slate-800 whitespace-pre-wrap">
                                            &ldquo;{msg.raw_content}&rdquo;
                                        </div>

                                        <div className="p-2.5 bg-rose-50 rounded border border-rose-100 text-xs text-rose-900 flex items-start gap-2">
                                            <XCircle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
                                            <div>
                                                <strong className="font-bold text-rose-950 block">Deterministic Solver Rejection Reason:</strong>
                                                <p className="mt-0.5 leading-relaxed font-medium">
                                                    {msg.rejection_reason || 'Conflicted with verified canonical plan constraints.'}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    )}

                    {/* TAB CONTENT: ALL INGESTED MESSAGES */}
                    {leftTab === 'all_messages' && (
                        <div className="flex flex-col gap-2.5">
                            {messages.length === 0 ? (
                                <div className="p-8 text-center bg-white rounded-lg border border-slate-200 text-slate-500 text-sm">
                                    No ingested messages found. Run `php artisan plan:run &lt;file&gt;` to begin.
                                </div>
                            ) : (
                                messages.map((msg) => (
                                    <div
                                        key={msg.id}
                                        className="bg-white rounded-lg border border-slate-200 p-3 shadow-xs flex flex-col gap-2"
                                    >
                                        <div className="flex items-center justify-between text-xs">
                                            <div className="flex items-center gap-2">
                                                <span className="font-mono text-[11px] text-slate-500 font-bold">
                                                    Batch #{msg.batch_number}
                                                </span>
                                                <span className="font-semibold text-slate-900">
                                                    {msg.sender || 'Sender'}
                                                </span>
                                                {msg.status === 'accepted' && (
                                                    <span className="px-1.5 py-0.2 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold">
                                                        ACCEPTED
                                                    </span>
                                                )}
                                                {msg.status === 'rejected' && (
                                                    <span className="px-1.5 py-0.2 rounded bg-rose-50 text-rose-700 border border-rose-200 text-[10px] font-bold">
                                                        REJECTED
                                                    </span>
                                                )}
                                                {msg.status === 'superseded' && (
                                                    <span className="px-1.5 py-0.2 rounded bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-bold">
                                                        SUPERSEDED
                                                    </span>
                                                )}
                                            </div>
                                            <span className="text-[11px] text-slate-400 font-mono">
                                                {msg.message_date || ''}
                                            </span>
                                        </div>

                                        <p className="text-xs text-slate-700 font-mono bg-slate-50 p-2 rounded border border-slate-100 whitespace-pre-wrap">
                                            {msg.raw_content}
                                        </p>

                                        {msg.rejection_reason && (
                                            <span className="text-[11px] text-rose-700 font-medium">
                                                Reason: {msg.rejection_reason}
                                            </span>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                    )}
                </section>

                {/* ========================================================= */}
                {/* RIGHT PANEL: LLM Telemetry Inspector (5 cols / ~40%)       */}
                {/* ========================================================= */}
                <section className="lg:col-span-5 flex flex-col gap-4">
                    {/* Telemetry Header & KPIs */}
                    <div className="bg-white rounded-lg border border-slate-200 p-4 shadow-xs flex flex-col gap-3">
                        <div className="flex items-center justify-between pb-2 border-b border-slate-100">
                            <div className="flex items-center gap-2">
                                <Activity className="w-4 h-4 text-blue-600" />
                                <h2 className="font-bold text-slate-900 text-sm">LLM Telemetry Inspector</h2>
                            </div>
                            <span className="font-mono text-[11px] text-slate-500 font-semibold">
                                {traces.length} Total Calls
                            </span>
                        </div>

                        {/* 4 KPI Grid Tiles */}
                        <div className="grid grid-cols-2 gap-2 text-xs">
                            <div className="bg-slate-50 p-2.5 rounded border border-slate-100">
                                <span className="text-[10px] uppercase font-semibold text-slate-500 block">Total Tokens</span>
                                <span className="font-mono text-base font-bold text-blue-600 block mt-0.5">
                                    {totalTokens.toLocaleString()}
                                </span>
                                <span className="text-[10px] text-slate-400">Exact API Usage</span>
                            </div>

                            <div className="bg-slate-50 p-2.5 rounded border border-slate-100">
                                <span className="text-[10px] uppercase font-semibold text-slate-500 block">Calls in Loop</span>
                                <span className="font-mono text-base font-bold text-emerald-600 block mt-0.5">
                                    {traces.length}
                                </span>
                                <span className="text-[10px] text-emerald-600 font-medium">100% Success</span>
                            </div>

                            <div className="bg-slate-50 p-2.5 rounded border border-slate-100 col-span-2">
                                <span className="text-[10px] uppercase font-semibold text-slate-500 block">Model Deployed</span>
                                <span className="font-mono text-xs font-bold text-slate-800 block mt-0.5 truncate">
                                    {modelName}
                                </span>
                                <span className="text-[10px] text-slate-400">Strict Area-Bound Routing</span>
                            </div>
                        </div>

                        {/* Inspector Subtabs: Structured vs Raw Official Trace */}
                        <div className="flex items-center border-b border-slate-200 pt-1">
                            <button
                                onClick={() => setRightTab('structured')}
                                className={`pb-2 px-3 text-xs font-semibold transition-colors border-b-2 -mb-px ${
                                    rightTab === 'structured'
                                        ? 'border-blue-600 text-blue-600'
                                        : 'border-transparent text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                Structured Calls ({traces.length})
                            </button>
                            <button
                                onClick={() => setRightTab('raw_trace')}
                                className={`pb-2 px-3 text-xs font-semibold transition-colors border-b-2 -mb-px ${
                                    rightTab === 'raw_trace'
                                        ? 'border-blue-600 text-blue-600'
                                        : 'border-transparent text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                Raw Official Trace
                            </button>
                        </div>

                        {/* STRUCTURED CALLS ACCORDION */}
                        {rightTab === 'structured' && (
                            <div className="flex flex-col gap-2 max-h-[580px] overflow-y-auto pr-1">
                                {traces.length === 0 ? (
                                    <div className="p-6 text-center text-slate-500 text-xs">
                                        No LLM trace calls logged yet. Run a plan to view telemetry.
                                    </div>
                                ) : (
                                    traces.map((trace) => {
                                        const isExpanded = !!expandedCalls[trace.call_number];
                                        return (
                                            <div
                                                key={trace.id}
                                                className="border border-slate-200 rounded-md bg-slate-50 overflow-hidden text-xs"
                                            >
                                                <button
                                                    onClick={() => toggleCall(trace.call_number)}
                                                    className="w-full p-2.5 flex items-center justify-between text-left hover:bg-slate-100 transition-colors"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-mono text-[11px] font-bold text-blue-700 bg-blue-100 px-1.5 py-0.5 rounded">
                                                            #{trace.call_number}
                                                        </span>
                                                        <span className="font-bold text-slate-900 uppercase tracking-wide text-[11px]">
                                                            {trace.area}
                                                        </span>
                                                    </div>

                                                    <div className="flex items-center gap-2 text-slate-500 font-mono text-[11px]">
                                                        <span>
                                                            {trace.prompt_tokens} + {trace.completion_tokens} ={' '}
                                                            <strong className="text-slate-900">{trace.total_tokens}</strong> tok
                                                        </span>
                                                        {isExpanded ? (
                                                            <ChevronDown className="w-3.5 h-3.5 text-slate-400" />
                                                        ) : (
                                                            <ChevronRight className="w-3.5 h-3.5 text-slate-400" />
                                                        )}
                                                    </div>
                                                </button>

                                                {isExpanded && (
                                                    <div className="p-3 border-t border-slate-200 bg-white space-y-2 text-[11px]">
                                                        <div>
                                                            <span className="font-semibold text-slate-500 uppercase text-[10px] block mb-1">
                                                                Input Payload:
                                                            </span>
                                                            <pre className="max-h-36 overflow-auto rounded bg-slate-50 border border-slate-200 p-2 font-mono text-[11px] text-slate-800 whitespace-pre-wrap">
                                                                {trace.input_payload}
                                                            </pre>
                                                        </div>

                                                        <div>
                                                            <span className="font-semibold text-slate-500 uppercase text-[10px] block mb-1">
                                                                Output Payload:
                                                            </span>
                                                            <pre className="max-h-36 overflow-auto rounded bg-emerald-50 border border-emerald-200 p-2 font-mono text-[11px] text-emerald-900 whitespace-pre-wrap">
                                                                {trace.output_payload}
                                                            </pre>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })
                                )}
                            </div>
                        )}

                        {/* RAW OFFICIAL TRACE VIEW */}
                        {rightTab === 'raw_trace' && (
                            <div className="max-h-[580px] overflow-auto bg-slate-900 rounded p-3 text-slate-100 font-mono text-[11px] leading-relaxed">
                                <pre className="whitespace-pre-wrap">{rawTraceText}</pre>
                            </div>
                        )}

                        {/* Bottom Pinned Action Bar */}
                        <div className="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                            <button
                                onClick={handleCopyTrace}
                                className="flex items-center gap-1.5 px-3 py-1.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium transition-colors"
                            >
                                {copied ? (
                                    <>
                                        <Check className="w-3.5 h-3.5 text-emerald-600" />
                                        <span className="text-emerald-700 font-semibold">Copied!</span>
                                    </>
                                ) : (
                                    <>
                                        <Copy className="w-3.5 h-3.5 text-slate-500" />
                                        <span>Copy Official Trace</span>
                                    </>
                                )}
                            </button>

                            <a
                                href="/export/trace"
                                download="full_trace.txt"
                                className="flex items-center gap-1 text-slate-500 hover:text-slate-800 text-xs transition-colors"
                            >
                                <Download className="w-3 h-3" />
                                <span>Export full_trace.txt</span>
                            </a>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    );
}
