import Avatar from '@/Components/Avatar';
import CompleteSprintModal from '@/Components/CompleteSprintModal';
import IssueFilters, { Filters } from '@/Components/IssueFilters';
import IssueFormModal from '@/Components/IssueFormModal';
import IssueTypeIcon from '@/Components/IssueTypeIcon';
import PriorityIcon from '@/Components/PriorityIcon';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { ProjectHeader } from '@/Components/ProjectNav';
import { useCreateShortcut } from '@/Components/useCreateShortcut';
import { placement, useLaneDroppable, useSortableLanes } from '@/Components/useSortableLanes';
import { issueUrl, onPeekClick } from '@/issueUrl';
import EmptyState from '@/Components/EmptyState';
import IssueDrawer from '@/Components/IssueDrawer';
import ResolutionChip, { resolutionLabels } from '@/Components/ResolutionChip';
import { categoryDot } from '@/Components/StatusLozenge';
import { transition } from '@/motion';
import { Calendar, CircleAlert, Inbox } from 'lucide-react';
import { motion } from 'motion/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { UNDO_LIFETIME } from '@/undo';
import { IssueOptions, Project, Resolution, StatusCategory } from '@/types';
import { closestCorners, DndContext, DragOverlay } from '@dnd-kit/core';
import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

export interface Card {
    key: string;
    title: string;
    type: string;
    priority: string;
    status: string;
    category: StatusCategory;
    status_color: string | null;
    resolution: Resolution | null;
    assignee: { name: string; email: string } | null;
    labels: string[];
    story_points: number | null;
    due_date: string | null;
    parent: string | null;
    /** Spec 7.11: what an edit from the card (the resolution chip) sends back. */
    version: number;
}

interface BoardData {
    columns: { name: string; position: number; statuses: { name: string; category: StatusCategory; color: string | null }[]; issues: Card[] }[];
    truncated: boolean;
}


const toLanes = (board: BoardData) =>
    Object.fromEntries(board.columns.map((c) => [c.name, c.issues])) as Record<string, Card[]>;

export default function Board({
    project,
    board,
    active_sprint: sprint,
    filters,
    options,
    can,
}: {
    project: Project;
    board: BoardData;
    active_sprint: { id: number; name: string; goal: string | null; start_date: string | null; end_date: string | null } | null;
    filters: Filters;
    options: IssueOptions;
    can: { contribute: boolean };
}) {
    const source = useMemo(() => toLanes(board), [board]);
    const [moved, setMoved] = useState('');
    // The card just resolved by a drop shows its "Resolved" chip for as long as the Undo toast.
    const [fresh, setFresh] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    useEffect(() => () => clearTimeout(timer.current), []);
    const { lanes, active, sensors, reset, handlers } = useSortableLanes(source, (key, lane, laneChanged, index, cards) => {
        setMoved(`${key} moved to ${lane}, position ${index + 1} of ${cards.length}`);
        if (laneChanged && board.columns.find((c) => c.name === lane)!.statuses[0].category === 'done') {
            setFresh(key);
            clearTimeout(timer.current);
            timer.current = setTimeout(() => setFresh((now) => (now === key ? null : now)), UNDO_LIFETIME);
        }
        router.post(
            route('issues.move', key),
            // Spec 7.4: a drop takes the column's first status (lanes are keyed by column name).
            { ...(laneChanged ? { status: board.columns.find((c) => c.name === lane)!.statuses[0].name } : {}), ...placement(index, cards) },
            { preserveScroll: true, preserveState: true, onError: reset },
        );
    });
    const [creating, setCreating] = useState(false);
    const [completing, setCompleting] = useState(false);
    const openCreate = useCallback(() => setCreating(true), []);
    useCreateShortcut(openCreate, can.contribute);
    const total = board.columns.reduce((n, c) => n + c.issues.length, 0);
    const hasFilters = Object.values(filters).some(Boolean);
    const clearFilters = () => router.get(route('projects.show', project.key), {}, { preserveState: true, replace: true });
    const sprintOnly = !!sprint && (filters.sprint === 'active' || filters.sprint === sprint.name);

    const applyFilters = (next: Filters) =>
        router.get(
            route('projects.show', project.key),
            Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v)),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <AuthenticatedLayout
            project={project}
            header={
                // The board shows every issue unless filtered, so the sprint is the title only when the board is scoped to it.
                <ProjectHeader project={project} title={sprintOnly ? sprint!.name : `${project.key} board`}>
                    {sprint && (
                        <span className="text-muted" title={sprint.end_date ? `${sprint.start_date} to ${sprint.end_date}` : undefined}>
                            {!sprintOnly && (
                                <Link href={route('projects.show', [project.key, { sprint: 'active' }])} className="font-medium text-primary hover:underline">
                                    {sprint.name}
                                </Link>
                            )}
                            {!sprintOnly && sprint.end_date && ' · '}
                            {sprint.end_date && daysLeft(sprint.end_date)}
                        </span>
                    )}
                    {sprint && can.contribute && <SecondaryButton onClick={() => setCompleting(true)}>Complete sprint</SecondaryButton>}
                    {can.contribute && <PrimaryButton onClick={openCreate}>Create issue</PrimaryButton>}
                </ProjectHeader>
            }
        >
            <Head title={`${project.key} board`} />

            <div className="px-4 pb-4 md:px-8">
                {sprintOnly && sprint?.goal && <p className="-mt-1 mb-3 text-muted">{sprint.goal}</p>}
                <div className="mb-4">
                    <IssueFilters
                        filters={filters}
                        options={options}
                        types={options.types.filter((t) => t !== 'epic')}
                        onApply={applyFilters}
                        onClear={clearFilters}
                    />
                </div>

                {board.truncated && (
                    <p className="mb-3 rounded-ctl tint-warn px-3 py-2">
                        Showing the first 500 issues. Use filters to narrow the board.
                    </p>
                )}

                {total === 0 && !hasFilters ? (
                    <EmptyState
                        icon={Inbox}
                        title="No issues yet."
                        hint={can.contribute ? 'Create your first issue.' : undefined}
                        action={can.contribute ? { label: 'Create issue', onClick: openCreate, shortcut: 'C' } : undefined}
                    />
                ) : total === 0 ? (
                    <EmptyState icon={Inbox} title="No issues match these filters." hint="Try removing a filter." action={{ label: 'Clear filters', onClick: clearFilters }} />
                ) : (
                    <DndContext sensors={sensors} collisionDetection={closestCorners} {...handlers}>
                        <div className="flex snap-x items-start gap-2.5 overflow-x-auto pb-4">
                            {board.columns.map(({ name, statuses }) => (
                                <Lane key={name} name={name} category={statuses[0].category} color={statuses[0].color} cards={lanes[name] ?? []} draggable={can.contribute} fresh={fresh} busy={busy} onBusy={setBusy} />
                            ))}
                        </div>
                        <DragOverlay dropAnimation={css('drop')}>
                            {active ? (
                                <motion.div initial={{ scale: 1, rotate: 0 }} animate={{ scale: 1.04, rotate: 1.5, transition: transition('lift') }}>
                                    <CardView card={active} dragging />
                                </motion.div>
                            ) : null}
                        </DragOverlay>
                    </DndContext>
                )}
                {/* Accessibility page: each move is announced politely, whether made with the mouse or the keyboard. */}
                <p role="status" aria-live="polite" className="sr-only">
                    {moved}
                </p>
            </div>

            {sprint && (
                <CompleteSprintModal
                    project={project}
                    sprint={completing ? sprint : null}
                    planned={options.sprints.filter((n) => n !== sprint.name)}
                    onClose={() => setCompleting(false)}
                />
            )}
            {can.contribute && (
                <IssueFormModal show={creating} onClose={() => setCreating(false)} projectKey={project.key} options={options} />
            )}
            <IssueDrawer />
        </AuthenticatedLayout>
    );
}

const daysLeft = (end: string) => {
    const days = Math.ceil((new Date(`${end}T23:59:59`).getTime() - Date.now()) / 86_400_000);
    return days < 0 ? 'Sprint ended' : `${days} day${days === 1 ? '' : 's'} left`;
};

// Sortable and drop timings come from motion.ts, so reduced motion turns them off with everything else.
const css = (moment: 'lift' | 'drop') => {
    const t = transition(moment);
    return { duration: t.duration * 1000, easing: `cubic-bezier(${t.ease.join(',')})` };
};

/** Design system BoardColumn: status square (its first status's category), name, count; the points total; the cards; a dashed target when empty. */
function Lane({ name, category, color, cards, draggable, fresh, busy, onBusy }: { name: string; category: StatusCategory; color: string | null; cards: Card[]; draggable: boolean; fresh: string | null; busy: string | null; onBusy: (key: string | null) => void }) {
    const { setNodeRef, isOver } = useLaneDroppable(name);
    // shortcut: totals cover the cards on screen, so a board cut off at 500 issues (the notice above says so) or a filtered
    // board shows partial sums; move the sum to BoardService when estimates get a unit (step 10).
    const points = cards.reduce((sum, c) => sum + (c.story_points ?? 0), 0);

    return (
        <section
            ref={setNodeRef}
            aria-label={`${name} column`}
            className={`flex min-h-[calc(100vh-17rem)] w-[280px] min-w-column-min-w shrink-0 snap-start flex-col rounded-col border p-2.5 transition-colors duration-instant ${
                isOver ? 'border-c-cobalt tint-cobalt' : 'border-transparent bg-card-2'
            }`}
        >
            <h3 className="flex items-center gap-2 px-1.5 pt-1 text-md text-text">
                <span aria-hidden="true" className={`h-[9px] w-[9px] shrink-0 rounded-[3px] ${categoryDot(category, color)}`} />
                <span className="truncate">{name}</span>
                <span className="rounded-pill bg-well px-2 text-xs text-muted">{cards.length}</span>
            </h3>
            <p className="px-1.5 pb-2.5 pt-1 text-sm text-muted">
                <span className="text-xl text-text">{points} pts</span> · {cards.length} {cards.length === 1 ? 'issue' : 'issues'}
            </p>
            <SortableContext items={cards.map((c) => c.key)} strategy={verticalListSortingStrategy}>
                <ul className="flex min-h-16 flex-1 flex-col gap-2">
                    {cards.map((card) => (
                        <SortableCard key={card.key} card={card} disabled={!draggable} chip={fresh === card.key || busy === card.key} onBusy={(b) => onBusy(b ? card.key : null)} />
                    ))}
                    {cards.length === 0 && (
                        <li>
                            <EmptyState compact title={`Nothing in ${name}.`} hint={draggable ? 'Drag an issue here.' : undefined} />
                        </li>
                    )}
                </ul>
            </SortableContext>
        </section>
    );
}

function SortableCard({ card, disabled, chip, onBusy }: { card: Card; disabled: boolean; chip: boolean; onBusy: (busy: boolean) => void }) {
    const { attributes, listeners, setNodeRef, transform, transition: sliding, isDragging } = useSortable({ id: card.key, disabled, transition: css('drop') });

    return (
        <li ref={setNodeRef} style={{ transform: CSS.Transform.toString(transform), transition: sliding }} {...attributes} {...listeners} className="rounded-item">
            {/* The dashed ghost marks where the card will land. */}
            {isDragging ? <div className="h-[88px] rounded-item border border-dashed border-line-2" /> : <CardView card={card} chip={chip ? { editable: !disabled, onBusy } : undefined} />}
        </li>
    );
}

const dueState = (date: string) => {
    const days = Math.ceil((new Date(`${date}T23:59:59`).getTime() - Date.now()) / 86_400_000);
    return days < 0 ? 'overdue' : days <= 2 ? 'soon' : 'later';
};

/** Design system IssueCard: type chip and key, two-line title, labels, then estimate, priority, due date and assignee. */
function CardView({ card, dragging = false, chip }: { card: Card; dragging?: boolean; chip?: { editable: boolean; onBusy: (busy: boolean) => void } }) {
    const due = card.due_date ? dueState(card.due_date) : null;
    // Closed without being done (Won't do, Duplicate…): stays on the board dimmed, with the resolution named in text.
    const dimmed = !!card.resolution && card.resolution !== 'done';

    return (
        <div className={`rounded-item border bg-card p-3 text-text ${dimmed ? 'opacity-60' : ''} ${dragging ? 'border-line-2 shadow-lift' : 'border-transparent shadow-xs hover:border-line-2'}`}>
            <div className="flex items-center gap-2">
                <IssueTypeIcon type={card.type} subtask={!!card.parent} />
                <span className="text-2xs text-muted" title={card.parent ? `Sub-task of ${card.parent}` : undefined}>
                    {card.key}
                </span>
            </div>
            <Link href={issueUrl(card.key)} onClick={onPeekClick(card.key)} title={card.title} className="mt-1.5 line-clamp-2 break-words text-md text-text hover:underline">
                {card.title}
            </Link>
            {dimmed && <p className="mt-1 text-sm text-muted">{resolutionLabels[card.resolution!]}</p>}
            {chip && card.resolution && (
                <div className="mt-2">
                    <ResolutionChip issueKey={card.key} version={card.version} resolution={card.resolution} editable={chip.editable} onBusy={chip.onBusy} />
                </div>
            )}
            {card.labels.length > 0 && <p className="mt-1 truncate text-sm text-muted">{card.labels.join(' · ')}</p>}
            <div className="mt-2 flex items-center gap-2 text-sm text-muted">
                {card.story_points !== null && <span>{card.story_points} pts</span>}
                <PriorityIcon priority={card.priority} />
                {card.due_date && (
                    <span className={`flex items-center gap-1 ${due === 'overdue' ? 'text-t-bad' : due === 'soon' ? 'text-t-warn' : ''}`}>
                        {due === 'overdue' ? <CircleAlert className="h-3.5 w-3.5" aria-hidden="true" /> : <Calendar className="h-3.5 w-3.5" aria-hidden="true" />}
                        {due === 'overdue' ? 'Overdue ' : 'Due '}
                        {new Date(`${card.due_date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}
                    </span>
                )}
                {card.assignee && <Avatar name={card.assignee.name} email={card.assignee.email} className="ms-auto" />}
            </div>
        </div>
    );
}
