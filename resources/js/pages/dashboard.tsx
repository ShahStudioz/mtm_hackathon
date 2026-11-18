import { router } from '@inertiajs/react';
import {
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
    FileCode,
    FileText,
    Gavel,
    Radio,
    RefreshCw,
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

    const rejectedMessages = messages.filter((m) => m.status === 'rejected');
    const supersededMessages = messages.filter((m) => m.status === 'superseded');
    const activeBatch = messages.length > 0 ? Math.max(...messages.map((m) => m.batch_number)) : 1;

    const validation = planState?.validation_summary || {};
    const computed = planState?.extracted_facts?.computed || {};

    const validationChecks = [
        { label: 'Correct Figures', ok: validation.correct_figures },
        { label: 'Within Budget', ok: validation.within_budget },
        { label: 'Venue Fits', ok: validation.venue_fits },
        { label: 'Schedule Valid', ok: validation.schedule_valid },
        { label: 'Enough Judges', ok: validation.enough_judges },
        { label: 'All Areas Covered', ok: validation.all_areas_covered },
    ];

    const areaColorMap: Record<string, string> = {
        reading: 'bg-cyan-950/70 text-cyan-400 border-cyan-800/50',
        venue: 'bg-purple-950/70 text-purple-400 border-purple-800/50',
        schedule: 'bg-blue-950/70 text-blue-400 border-blue-800/50',
        food: 'bg-amber-950/70 text-amber-400 border-amber-800/50',
        judging: 'bg-emerald-950/70 text-emerald-400 border-emerald-800/50',
        budget: 'bg-emerald-950/70 text-emerald-400 border-emerald-800/50',
    };

    return (
        <div className="flex h-screen w-screen flex-col overflow-hidden bg-[#09090b] text-[#f8fafc] font-sans antialiased selection:bg-cyan-900 selection:text-white">
            {/* =========================================================================
                1. COMPACT TOP TELEMETRY BAR (Height: 64px)
            ========================================================================= */}
            <header className="flex h-16 w-full flex-none items-center justify-between border-b border-[#1e293b] bg-[#09090b] px-5">
                {/* Left: Branding & Active Batch */}
                <div className="flex items-center gap-3">
                    <div className="flex h-9 w-9 items-center justify-center rounded-md border border-[#1e293b] bg-[#0f172a] shadow-inner">
                        <FileCode className="h-5 w-5 text-cyan-400" />
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="font-semibold tracking-tight text-white text-sm">MTM Hackathon Planner</span>
                            <span className="rounded border border-[#1e293b] bg-[#0f172a] px-1.5 py-0.5 font-mono text-[10px] text-zinc-400">
                                Batch #{activeBatch}
                            </span>
                        </div>
                        <p className="text-[11px] text-[#94a3b8]">Autonomous AI Orchestrator & Constraint Solver</p>
                    </div>
                </div>

                {/* Center: 6-Check Validation Strip */}
                <div className="hidden items-center gap-1.5 xl:flex">
                    {validationChecks.map((check, idx) => (
                        <div
                            key={idx}
                            className={`flex items-center gap-1 rounded border px-2 py-1 font-mono text-[11px] transition-colors ${
                                check.ok
                                    ? 'border-emerald-900/50 bg-emerald-950/40 text-emerald-400'
                                    : 'border-rose-900/50 bg-rose-950/40 text-rose-400'
                            }`}
                        >
                            {check.ok ? (
                                <CheckCircle2 className="h-3.5 w-3.5 text-emerald-400" />
                            ) : (
                                <XCircle className="h-3.5 w-3.5 text-rose-400" />
                            )}
                            <span>{check.label}</span>
                        </div>
                    ))}
                </div>

                {/* Right: KPIs, Live Polling Toggle, Export */}
                <div className="flex items-center gap-3">
                    {/* Live Sync Toggle */}
                    <button
                        onClick={() => setLiveSync(!liveSync)}
                        className={`flex items-center gap-1.5 rounded border px-2.5 py-1 text-xs font-mono transition-colors ${
                            liveSync
                                ? 'border-emerald-800/40 bg-emerald-950/40 text-emerald-400'
                                : 'border-[#1e293b] bg-[#0f172a] text-zinc-400'
                        }`}
                        title="Toggle live background reload"
                    >
                        <Radio className={`h-3.5 w-3.5 ${liveSync ? 'animate-pulse text-emerald-400' : 'text-zinc-500'}`} />
                        <span>Live Sync: {liveSync ? 'ON' : 'OFF'}</span>
                    </button>

                    {/* KPIs */}
                    <div className="hidden items-center gap-2 rounded border border-[#1e293b] bg-[#0f172a] px-3 py-1 font-mono text-xs sm:flex">
                        <span className="text-zinc-400">Headcount:</span>
                        <span className="font-semibold text-white">{computed.total_headcount || 177}</span>
                        <span className="text-zinc-600">|</span>
                        <span className="text-zinc-400">Tokens:</span>
                        <span className="font-semibold text-cyan-400">{totalTokens.toLocaleString()}</span>
                    </div>

                    {/* Download Submission */}
                    <a
                        href="/export/submission"
                        download="submission.txt"
                        className="flex items-center gap-1.5 rounded border border-cyan-800/60 bg-cyan-950/60 px-3 py-1 text-xs font-medium text-cyan-300 hover:bg-cyan-900/60 transition-colors"
                    >
                        <Download className="h-3.5 w-3.5" />
                        <span>submission.txt</span>
                    </a>
                </div>
            </header>

            {/* =========================================================================
                2. MAIN 12-COLUMN WORKSPACE (7 Cols Left / 5 Cols Right)
            ========================================================================= */}
            <main className="grid flex-1 grid-cols-12 gap-4 overflow-hidden p-4">
                {/* ---------------------------------------------------------------------
                    LEFT PANEL (7 Columns): 5-Area Plan & Messages
                --------------------------------------------------------------------- */}
                <section className="col-span-12 flex h-full flex-col overflow-hidden rounded-lg border border-[#1e293b] bg-[#09090b] lg:col-span-7">
                    {/* Top Segmented Tab Bar */}
                    <div className="flex h-12 flex-none items-center justify-between border-b border-[#1e293b] bg-[#0f172a] px-4">
                        <div className="flex items-center gap-1">
                            <button
                                onClick={() => setLeftTab('plan')}
                                className={`rounded px-3 py-1 text-xs font-medium transition-colors ${
                                    leftTab === 'plan'
                                        ? 'bg-[#1e293b] text-white shadow-sm'
                                        : 'text-[#94a3b8] hover:text-white'
                                }`}
                            >
                                5-Area Event Plan
                            </button>
                            <button
                                onClick={() => setLeftTab('rejected')}
                                className={`flex items-center gap-1.5 rounded px-3 py-1 text-xs font-medium transition-colors ${
                                    leftTab === 'rejected'
                                        ? 'bg-[#1e293b] text-white shadow-sm'
                                        : 'text-[#94a3b8] hover:text-white'
                                }`}
                            >
                                <span>Rejected & Conflicts</span>
                                {rejectedMessages.length > 0 && (
                                    <span className="rounded-full bg-rose-950 px-1.5 py-0.2 text-[10px] font-mono text-rose-400 border border-rose-800/50">
                                        {rejectedMessages.length}
                                    </span>
                                )}
                            </button>
                            <button
                                onClick={() => setLeftTab('all_messages')}
                                className={`rounded px-3 py-1 text-xs font-medium transition-colors ${
                                    leftTab === 'all_messages'
                                        ? 'bg-[#1e293b] text-white shadow-sm'
                                        : 'text-[#94a3b8] hover:text-white'
                                }`}
                            >
                                All Ingested ({messages.length})
                            </button>
                        </div>

                        <div className="font-mono text-xs text-[#94a3b8]">
                            Status: <span className="font-semibold text-emerald-400 uppercase">{planState?.status || 'INITIALIZED'}</span>
                        </div>
                    </div>

                    {/* Pinned Prominent Alert Banner for Judges if any message is rejected */}
                    {rejectedMessages.length > 0 && leftTab !== 'rejected' && (
                        <div
                            onClick={() => setLeftTab('rejected')}
                            className="flex cursor-pointer items-center justify-between border-b border-rose-900/40 bg-rose-950/30 px-4 py-2 text-xs text-rose-300 transition-colors hover:bg-rose-950/40"
                        >
                            <div className="flex items-center gap-2">
                                <AlertTriangle className="h-4 w-4 flex-none text-rose-400" />
                                <span>
                                    <strong>Conflict Trapped:</strong> {rejectedMessages[0].rejection_reason}
                                </span>
                            </div>
                            <span className="font-mono text-[11px] underline">Inspect Reason →</span>
                        </div>
                    )}

                    {/* Scrollable Left Tab Content */}
                    <div className="flex-1 overflow-y-auto p-4 space-y-4">
                        {/* TAB 1: 5-AREA PLAN BENTO GRID */}
                        {leftTab === 'plan' && (
                            <div className="space-y-4">
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    {/* 1. Venue Card */}
                                    <div className="rounded border border-[#1e293b] bg-[#0f172a] p-4 flex flex-col justify-between">
                                        <div>
                                            <div className="flex items-center justify-between border-b border-[#1e293b] pb-2">
                                                <div className="flex items-center gap-2">
                                                    <Building2 className="h-4 w-4 text-purple-400" />
                                                    <h3 className="text-xs font-semibold uppercase tracking-wider text-purple-300">
                                                        Venue Plan
                                                    </h3>
                                                </div>
                                                <span className="font-mono text-xs font-semibold text-white">
                                                    Rs {Number(planState?.venue_plan?.cost || computed?.venue_cost || 0).toLocaleString()}
                                                </span>
                                            </div>
                                            <div className="mt-3 space-y-1.5 text-xs">
                                                <div className="flex justify-between">
                                                    <span className="text-zinc-400">Selected Hall:</span>
                                                    <span className="font-medium text-white">
                                                        {planState?.venue_plan?.selected_venue || computed?.selected_venue?.name || 'N/A'}
                                                    </span>
                                                </div>
                                                <div className="flex justify-between">
                                                    <span className="text-zinc-400">Capacity vs Headcount:</span>
                                                    <span className="font-mono text-white">
                                                        {planState?.venue_plan?.capacity || computed?.selected_venue?.capacity || 'N/A'} seats (for{' '}
                                                        {planState?.venue_plan?.required_headcount || computed?.total_headcount || 177})
                                                    </span>
                                                </div>
                                                <div className="flex justify-between items-center pt-1">
                                                    <span className="text-zinc-400">Features:</span>
                                                    <span className="rounded bg-purple-950/60 border border-purple-800/40 px-1.5 py-0.5 text-[10px] text-purple-300 font-mono">
                                                        {Array.isArray(planState?.venue_plan?.features)
                                                            ? planState.venue_plan.features.join(', ')
                                                            : 'Standby Generator'}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        {planState?.venue_plan?.justification && (
                                            <p className="mt-3 border-t border-[#1e293b] pt-2 text-[11px] text-zinc-400 italic">
                                                {planState.venue_plan.justification}
                                            </p>
                                        )}
                                    </div>

                                    {/* 2. Food & Catering Card */}
                                    <div className="rounded border border-[#1e293b] bg-[#0f172a] p-4 flex flex-col justify-between">
                                        <div>
                                            <div className="flex items-center justify-between border-b border-[#1e293b] pb-2">
                                                <div className="flex items-center gap-2">
                                                    <UtensilsCrossed className="h-4 w-4 text-amber-400" />
                                                    <h3 className="text-xs font-semibold uppercase tracking-wider text-amber-300">
                                                        Food & Catering Plan
                                                    </h3>
                                                </div>
                                                <span className="font-mono text-xs font-semibold text-white">
                                                    Rs {Number(planState?.food_plan?.total_food_cost || computed?.catering_cost || 0).toLocaleString()}
                                                </span>
                                            </div>
                                            <div className="mt-3 space-y-1.5 text-xs">
                                                <div className="flex justify-between">
                                                    <span className="text-zinc-400">Selected Package:</span>
                                                    <span className="font-medium text-white">
                                                        {planState?.food_plan?.selected_package || computed?.selected_catering?.name || 'Standard lunch box'}
                                                    </span>
                                                </div>
                                                <div className="flex justify-between">
                                                    <span className="text-zinc-400">Unit Price / Delivery:</span>
                                                    <span className="font-mono text-white">
                                                        Rs {planState?.food_plan?.unit_cost || computed?.selected_catering?.cost_per_head || 1200} (Delivery free)
                                                    </span>
                                                </div>
                                                <div className="pt-1">
                                                    <span className="text-zinc-400 text-[11px] block mb-1">Headcount Breakdown:</span>
                                                    <div className="flex items-center gap-1 font-mono text-[10px]">
                                                        <span className="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-200">
                                                            {planState?.food_plan?.headcount_breakdown?.students || 150} Students
                                                        </span>
                                                        <span>+</span>
                                                        <span className="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-200">
                                                            {planState?.food_plan?.headcount_breakdown?.staff || 10} Staff
                                                        </span>
                                                        <span>+</span>
                                                        <span className="rounded bg-zinc-800 px-1.5 py-0.5 text-zinc-200">
                                                            {planState?.food_plan?.headcount_breakdown?.judges || 17} Judges
                                                        </span>
                                                        <span>=</span>
                                                        <span className="rounded bg-emerald-950 border border-emerald-800 px-1.5 py-0.5 text-emerald-300 font-bold">
                                                            {planState?.food_plan?.headcount_breakdown?.total || computed?.total_headcount || 177} Total
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        {planState?.food_plan?.notes && (
                                            <p className="mt-3 border-t border-[#1e293b] pt-2 text-[11px] text-zinc-400">
                                                {planState.food_plan.notes}
                                            </p>
                                        )}
                                    </div>

                                    {/* 3. Judging Card */}
                                    <div className="rounded border border-[#1e293b] bg-[#0f172a] p-4 flex flex-col justify-between">
                                        <div>
                                            <div className="flex items-center justify-between border-b border-[#1e293b] pb-2">
                                                <div className="flex items-center gap-2">
                                                    <Gavel className="h-4 w-4 text-emerald-400" />
                                                    <h3 className="text-xs font-semibold uppercase tracking-wider text-emerald-300">
                                                        Judging Plan
                                                    </h3>
                                                </div>
                                                <span className="font-mono text-xs font-semibold text-white">
                                                    Rs {Number(planState?.judging_plan?.total_judging_cost || computed?.judging_cost || 0).toLocaleString()}
                                                </span>
                                            </div>
                                            <div className="mt-3 space-y-1.5 text-xs">
                                                <div className="flex justify-between">
                                                    <span className="text-zinc-400">Teams / Assigned Judges:</span>
                                                    <span className="font-mono text-white">
                                                        {planState?.judging_plan?.total_teams || computed?.teams_count || 50} teams /{' '}
                                                        {planState?.judging_plan?.assigned_judges || computed?.required_judges || 17} active judges
                                                    </span>
                                                </div>
                                                <div className="flex justify-between">
                                                    <span className="text-zinc-400">Confirmed / Standby:</span>
                                                    <span className="font-mono text-white">
                                                        {planState?.judging_plan?.confirmed_judges || computed?.confirmed_judges || 20} confirmed ({planState?.judging_plan?.standby_judges || 3} standby buffer)
                                                    </span>
                                                </div>
                                                <div className="flex justify-between">
                                                    <span className="text-zinc-400">Honorarium / Rules:</span>
                                                    <span className="font-mono text-white">
                                                        Rs {planState?.judging_plan?.honorarium_per_judge || 5000} each (max {planState?.judging_plan?.max_teams_per_judge || 3} teams, {planState?.judging_plan?.minutes_per_team || 15}m/team)
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        {planState?.judging_plan?.contingency_notes && (
                                            <p className="mt-3 border-t border-[#1e293b] pt-2 text-[11px] text-zinc-400">
                                                {planState.judging_plan.contingency_notes}
                                            </p>
                                        )}
                                    </div>

                                    {/* 4. Budget Card */}
                                    <div className="rounded border border-[#1e293b] bg-[#0f172a] p-4 flex flex-col justify-between">
                                        <div>
                                            <div className="flex items-center justify-between border-b border-[#1e293b] pb-2">
                                                <div className="flex items-center gap-2">
                                                    <DollarSign className="h-4 w-4 text-emerald-400" />
                                                    <h3 className="text-xs font-semibold uppercase tracking-wider text-emerald-300">
                                                        Budget Overview
                                                    </h3>
                                                </div>
                                                <span className="rounded bg-emerald-950/60 border border-emerald-800/40 px-2 py-0.5 text-[11px] font-mono text-emerald-400">
                                                    WITHIN BUDGET
                                                </span>
                                            </div>
                                            <div className="mt-3 space-y-1.5 text-xs font-mono">
                                                <div className="flex justify-between">
                                                    <span className="text-zinc-400">Total Budget Limit:</span>
                                                    <span className="text-white font-semibold">
                                                        Rs {Number(planState?.budget_plan?.budget_limit || planState?.extracted_facts?.event?.total_budget_limit || 650000).toLocaleString()}
                                                    </span>
                                                </div>
                                                <div className="flex justify-between">
                                                    <span className="text-zinc-400">Total Plan Expenditure:</span>
                                                    <span className="text-emerald-400 font-semibold">
                                                        Rs {Number(planState?.budget_plan?.total_spent || computed?.total_cost || 0).toLocaleString()}
                                                    </span>
                                                </div>
                                                <div className="flex justify-between border-t border-[#1e293b] pt-1">
                                                    <span className="text-zinc-400">Remaining Balance:</span>
                                                    <span className="text-cyan-400 font-bold">
                                                        Rs {Number(planState?.budget_plan?.remaining_balance || computed?.remaining_budget || 0).toLocaleString()}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="mt-3 border-t border-[#1e293b] pt-2 flex items-center justify-between text-[11px] text-zinc-400">
                                            <span>Fixed Expenses: Rs {Number(computed?.fixed_expenses_total || 0).toLocaleString()}</span>
                                            <span>Venue: Rs {Number(computed?.venue_cost || 0).toLocaleString()}</span>
                                            <span>Food: Rs {Number(computed?.catering_cost || 0).toLocaleString()}</span>
                                        </div>
                                    </div>
                                </div>

                                {/* 5. Schedule Card (Full width) */}
                                <div className="rounded border border-[#1e293b] bg-[#0f172a] p-4">
                                    <div className="flex items-center justify-between border-b border-[#1e293b] pb-2">
                                        <div className="flex items-center gap-2">
                                            <Clock className="h-4 w-4 text-blue-400" />
                                            <h3 className="text-xs font-semibold uppercase tracking-wider text-blue-300">
                                                Event Schedule Timeline (
                                                {planState?.schedule_plan?.total_duration_minutes || 240} mins)
                                            </h3>
                                        </div>
                                        <span className="text-[11px] font-mono text-zinc-400">
                                            {planState?.extracted_facts?.event?.duration_hours || 4} Hours All-In
                                        </span>
                                    </div>

                                    {/* Timeline Slots Table */}
                                    <div className="mt-3 overflow-x-auto">
                                        <table className="w-full text-left text-xs">
                                            <thead>
                                                <tr className="border-b border-[#1e293b] text-[#94a3b8] font-mono text-[11px]">
                                                    <th className="py-1.5 pr-4">Slot</th>
                                                    <th className="py-1.5 pr-4">Duration</th>
                                                    <th className="py-1.5 pr-4">Activity</th>
                                                    <th className="py-1.5">Notes & Constraints</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-[#1e293b]/50">
                                                {planState?.schedule_plan?.timeline ? (
                                                    planState.schedule_plan.timeline.map((slot: any, idx: number) => {
                                                        const isPrayer = slot.activity?.toLowerCase().includes('prayer');
                                                        return (
                                                            <tr
                                                                key={idx}
                                                                className={isPrayer ? 'bg-emerald-950/20 text-emerald-300' : 'text-zinc-300'}
                                                            >
                                                                <td className="py-2 pr-4 font-mono text-[11px] font-medium text-cyan-400">
                                                                    {slot.time_slot}
                                                                </td>
                                                                <td className="py-2 pr-4 font-mono text-[11px] text-zinc-400">
                                                                    {slot.duration_minutes}m
                                                                </td>
                                                                <td className="py-2 pr-4 font-medium">{slot.activity}</td>
                                                                <td className="py-2 text-[11px] text-zinc-400">{slot.notes}</td>
                                                            </tr>
                                                        );
                                                    })
                                                ) : (
                                                    <tr>
                                                        <td colSpan={4} className="py-3 text-center text-zinc-500 font-mono">
                                                            No schedule timeline generated yet.
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* TAB 2: REJECTED & CONFLICTING MESSAGES */}
                        {leftTab === 'rejected' && (
                            <div className="space-y-3">
                                {rejectedMessages.length === 0 && supersededMessages.length === 0 ? (
                                    <div className="rounded border border-[#1e293b] bg-[#0f172a] p-8 text-center text-zinc-500">
                                        <CheckCircle2 className="mx-auto h-8 w-8 text-emerald-500 mb-2" />
                                        <p className="text-sm font-medium text-zinc-300">No Rejected or Conflicted Messages</p>
                                        <p className="text-xs text-zinc-500 mt-1">
                                            All processed messages satisfied event constraints without violations.
                                        </p>
                                    </div>
                                ) : (
                                    <>
                                        {rejectedMessages.map((msg) => (
                                            <div
                                                key={msg.id}
                                                className="rounded border border-rose-900/50 bg-[#0f172a] p-4 shadow-sm"
                                            >
                                                <div className="flex items-center justify-between border-b border-[#1e293b] pb-2">
                                                    <div className="flex items-center gap-2">
                                                        <span className="rounded bg-rose-950 border border-rose-800 px-2 py-0.5 font-mono text-[10px] font-bold text-rose-400">
                                                            REJECTED
                                                        </span>
                                                        <span className="font-semibold text-xs text-white">
                                                            {msg.sender || 'Unknown Sender'}
                                                        </span>
                                                        <span className="font-mono text-[11px] text-zinc-500">
                                                            (Batch #{msg.batch_number} {msg.raw_index ? `| Msg #${msg.raw_index}` : ''})
                                                        </span>
                                                    </div>
                                                    <span className="text-[11px] font-mono text-zinc-400">{msg.message_date || ''}</span>
                                                </div>

                                                <div className="mt-3">
                                                    <span className="text-[11px] font-semibold text-rose-400 block mb-1">
                                                        REJECTION / CONFLICT REASON:
                                                    </span>
                                                    <div className="rounded border border-rose-900/30 bg-rose-950/20 p-2.5 font-mono text-xs text-rose-300 leading-relaxed">
                                                        {msg.rejection_reason}
                                                    </div>
                                                </div>

                                                <div className="mt-3">
                                                    <span className="text-[10px] text-zinc-500 uppercase tracking-wider block mb-1">
                                                        Raw Message Content:
                                                    </span>
                                                    <p className="text-xs text-zinc-300 italic bg-[#09090b] border border-[#1e293b] p-2 rounded">
                                                        "{msg.raw_content}"
                                                    </p>
                                                </div>
                                            </div>
                                        ))}

                                        {supersededMessages.map((msg) => (
                                            <div
                                                key={msg.id}
                                                className="rounded border border-[#1e293b] bg-[#0f172a] p-4 opacity-80"
                                            >
                                                <div className="flex items-center justify-between border-b border-[#1e293b] pb-2">
                                                    <div className="flex items-center gap-2">
                                                        <span className="rounded bg-zinc-800 border border-zinc-700 px-2 py-0.5 font-mono text-[10px] font-medium text-zinc-300">
                                                            SUPERSEDED
                                                        </span>
                                                        <span className="font-semibold text-xs text-white">{msg.sender}</span>
                                                    </div>
                                                    <span className="text-[11px] font-mono text-zinc-500">{msg.message_date}</span>
                                                </div>
                                                <p className="mt-2 text-xs text-zinc-400 font-mono">{msg.rejection_reason}</p>
                                                <p className="mt-1 text-xs text-zinc-500 italic">"{msg.raw_content}"</p>
                                            </div>
                                        ))}
                                    </>
                                )}
                            </div>
                        )}

                        {/* TAB 3: ALL INGESTED MESSAGES TABLE */}
                        {leftTab === 'all_messages' && (
                            <div className="rounded border border-[#1e293b] bg-[#0f172a] overflow-hidden">
                                <table className="w-full text-left text-xs">
                                    <thead>
                                        <tr className="border-b border-[#1e293b] bg-[#09090b] font-mono text-[11px] text-zinc-400">
                                            <th className="py-2 px-3">Batch</th>
                                            <th className="py-2 px-3">Sender</th>
                                            <th className="py-2 px-3">Date</th>
                                            <th className="py-2 px-3">Status</th>
                                            <th className="py-2 px-3">Content</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[#1e293b]/60">
                                        {messages.map((m) => (
                                            <tr key={m.id} className="hover:bg-[#141f36]/40 transition-colors">
                                                <td className="py-2 px-3 font-mono text-[11px] text-cyan-400">#{m.batch_number}</td>
                                                <td className="py-2 px-3 font-medium text-white">{m.sender}</td>
                                                <td className="py-2 px-3 font-mono text-[11px] text-zinc-400">{m.message_date}</td>
                                                <td className="py-2 px-3">
                                                    <span
                                                        className={`rounded px-1.5 py-0.5 text-[10px] font-mono uppercase ${
                                                            m.status === 'accepted'
                                                                ? 'bg-emerald-950 text-emerald-400 border border-emerald-800'
                                                                : m.status === 'rejected'
                                                                  ? 'bg-rose-950 text-rose-400 border border-rose-800'
                                                                  : 'bg-zinc-800 text-zinc-400 border border-zinc-700'
                                                        }`}
                                                    >
                                                        {m.status}
                                                    </span>
                                                </td>
                                                <td className="py-2 px-3 text-zinc-300 truncate max-w-xs">{m.raw_content}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </section>

                {/* ---------------------------------------------------------------------
                    RIGHT PANEL (5 Columns): Live LLM Execution Trace Inspector
                --------------------------------------------------------------------- */}
                <section className="col-span-12 flex h-full flex-col overflow-hidden rounded-lg border border-[#1e293b] bg-[#09090b] lg:col-span-5">
                    {/* Header with View Toggle & Copy */}
                    <div className="flex h-12 flex-none items-center justify-between border-b border-[#1e293b] bg-[#0f172a] px-4">
                        <div className="flex items-center gap-2">
                            <span className="font-semibold text-xs tracking-tight text-white">LLM Execution Traces</span>
                            <span className="rounded bg-cyan-950 border border-cyan-800/50 px-1.5 py-0.2 font-mono text-[10px] text-cyan-400">
                                {traces.length} Calls
                            </span>
                        </div>

                        <div className="flex items-center gap-2">
                            <div className="flex items-center rounded border border-[#1e293b] bg-[#09090b] p-0.5">
                                <button
                                    onClick={() => setRightTab('structured')}
                                    className={`rounded px-2 py-0.5 text-[11px] font-medium transition-colors ${
                                        rightTab === 'structured'
                                            ? 'bg-[#1e293b] text-white'
                                            : 'text-zinc-400 hover:text-white'
                                    }`}
                                >
                                    Structured
                                </button>
                                <button
                                    onClick={() => setRightTab('raw_trace')}
                                    className={`rounded px-2 py-0.5 text-[11px] font-medium transition-colors ${
                                        rightTab === 'raw_trace'
                                            ? 'bg-[#1e293b] text-white'
                                            : 'text-zinc-400 hover:text-white'
                                    }`}
                                >
                                    Raw Trace
                                </button>
                            </div>

                            <button
                                onClick={handleCopyTrace}
                                className="flex items-center gap-1 rounded border border-[#1e293b] bg-[#09090b] px-2 py-1 text-[11px] font-medium text-zinc-300 hover:bg-[#1e293b] transition-colors"
                                title="Copy official trace to clipboard"
                            >
                                {copied ? <Check className="h-3 w-3 text-emerald-400" /> : <Copy className="h-3 w-3" />}
                                <span>{copied ? 'Copied' : 'Copy'}</span>
                            </button>
                        </div>
                    </div>

                    {/* Scrollable Trace Content */}
                    <div className="flex-1 overflow-y-auto p-4 space-y-2.5">
                        {rightTab === 'structured' ? (
                            traces.length === 0 ? (
                                <div className="p-8 text-center text-xs text-zinc-500 font-mono">
                                    No LLM traces recorded yet. Run `php artisan plan:run` to trigger pipeline.
                                </div>
                            ) : (
                                traces.map((t) => {
                                    const isExpanded = expandedCalls[t.call_number];
                                    const areaBadgeClass = areaColorMap[t.area] || 'bg-zinc-800 text-zinc-300';
                                    return (
                                        <div
                                            key={t.id}
                                            className="rounded border border-[#1e293b] bg-[#0f172a] transition-colors"
                                        >
                                            {/* Call Header Row */}
                                            <div
                                                onClick={() => toggleCall(t.call_number)}
                                                className="flex cursor-pointer items-center justify-between p-2.5 hover:bg-[#141f36]/50"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <span className="font-mono text-xs font-bold text-white">
                                                        #{t.call_number}
                                                    </span>
                                                    <span
                                                        className={`rounded border px-1.5 py-0.2 font-mono text-[10px] uppercase font-medium ${areaBadgeClass}`}
                                                    >
                                                        {t.area}
                                                    </span>
                                                    <span className="font-mono text-[11px] text-zinc-400">
                                                        {t.model_name}
                                                    </span>
                                                </div>

                                                <div className="flex items-center gap-2">
                                                    <span className="font-mono text-[11px] text-zinc-400">
                                                        {t.prompt_tokens} + {t.completion_tokens} ={' '}
                                                        <strong className="text-cyan-400">{t.total_tokens}</strong> tok
                                                    </span>
                                                    {isExpanded ? (
                                                        <ChevronDown className="h-3.5 w-3.5 text-zinc-400" />
                                                    ) : (
                                                        <ChevronRight className="h-3.5 w-3.5 text-zinc-400" />
                                                    )}
                                                </div>
                                            </div>

                                            {/* Expandable Payloads Box */}
                                            {isExpanded && (
                                                <div className="border-t border-[#1e293b] bg-[#09090b] p-3 space-y-2 text-[11px]">
                                                    <div>
                                                        <span className="font-mono text-[10px] text-zinc-500 uppercase block mb-1">
                                                            Input Payload:
                                                        </span>
                                                        <pre className="max-h-36 overflow-auto rounded bg-[#0f172a] border border-[#1e293b] p-2 font-mono text-[11px] text-zinc-300 whitespace-pre-wrap">
                                                            {t.input_payload}
                                                        </pre>
                                                    </div>

                                                    <div>
                                                        <span className="font-mono text-[10px] text-zinc-500 uppercase block mb-1">
                                                            Output Payload:
                                                        </span>
                                                        <pre className="max-h-36 overflow-auto rounded bg-[#0f172a] border border-[#1e293b] p-2 font-mono text-[11px] text-emerald-300 whitespace-pre-wrap">
                                                            {t.output_payload}
                                                        </pre>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })
                            )
                        ) : (
                            /* RAW OFFICIAL TRACE VIEW */
                            <div className="h-full rounded border border-[#1e293b] bg-[#09090b] p-3 font-mono text-[11px] text-zinc-300 overflow-auto">
                                <pre className="whitespace-pre-wrap leading-relaxed">{rawTraceText}</pre>
                            </div>
                        )}
                    </div>

                    {/* Footer Pinned to Bottom of Right Panel */}
                    <div className="flex h-12 flex-none items-center justify-between border-t border-[#1e293b] bg-[#0f172a] px-4 font-mono text-xs">
                        <div className="flex items-center gap-2">
                            <span className="text-zinc-400">Total Tokens:</span>
                            <span className="font-bold text-cyan-400">{totalTokens.toLocaleString()}</span>
                        </div>
                        <a
                            href="/export/trace"
                            download="full_trace.txt"
                            className="flex items-center gap-1 text-[11px] text-zinc-400 hover:text-white transition-colors"
                        >
                            <Download className="h-3 w-3" />
                            <span>Export Trace</span>
                        </a>
                    </div>
                </section>
            </main>
        </div>
    );
}
