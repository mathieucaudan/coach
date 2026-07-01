const STORAGE_KEY = 'coach.mvp.local.v1';
const FREE_ATHLETE_LIMIT = 3;
const DEFAULT_VMA = 15;
const MISSING_SESSION_DAYS = 7;
const VMA_PACE_PERCENTAGES = [60, 70, 80, 90, 100, 105];

const state = {
  coach: null,
  athletes: [],
  selectedAthleteId: null,
};

const pages = {};
const today = new Date();

document.addEventListener('DOMContentLoaded', initApp);

function initApp() {
  pages.onboarding = document.querySelector('#onboarding-page');
  pages.coachForm = document.querySelector('#coach-form-page');
  pages.dashboard = document.querySelector('#dashboard-page');
  pages.athleteForm = document.querySelector('#athlete-form-page');
  pages.athleteDetail = document.querySelector('#athlete-detail-page');
  pages.sessionForm = document.querySelector('#session-form-page');

  loadState();
  bindEvents();
  enforceCoachGate();
  render();
  navigate(state.coach ? 'dashboard' : 'onboarding');
}

function bindEvents() {
  document.querySelectorAll('[data-nav]').forEach((button) => {
    button.addEventListener('click', () => handleNavigation(button.dataset.nav));
  });

  document.querySelector('[data-current-athlete]').addEventListener('click', () => {
    if (state.selectedAthleteId) {
      navigate('athleteDetail');
    }
  });

  document.querySelector('#coach-form').addEventListener('submit', handleCoachSubmit);
  document.querySelector('#athlete-form').addEventListener('submit', handleAthleteSubmit);
  document.querySelector('#session-form').addEventListener('submit', handleSessionSubmit);
  document.querySelector('#open-athlete-form').addEventListener('click', openAthleteForm);
  document.querySelector('#cancel-session').addEventListener('click', () => navigate('athleteDetail'));
}

function handleNavigation(pageName) {
  enforceCoachGate();

  if (!hasCoachAccount() && pageName !== 'onboarding' && pageName !== 'coach-form') {
    navigate('onboarding');
    return;
  }

  if (pageName === 'athlete-form') {
    openAthleteForm();
    return;
  }

  if (pageName === 'session-form') {
    openSessionForm();
    return;
  }

  navigate(pageName);
}

function handleCoachSubmit(event) {
  event.preventDefault();

  const data = new FormData(event.currentTarget);
  const coach = {
    id: createId(),
    firstName: clean(data.get('firstName')),
    lastName: clean(data.get('lastName')),
    email: clean(data.get('email')),
    club: clean(data.get('club')),
    createdAt: new Date().toISOString(),
  };

  if (!coach.firstName || !coach.lastName || !coach.email) {
    return;
  }

  state.coach = coach;
  enforceCoachGate();
  saveState();
  event.currentTarget.reset();
  render();
  navigate('dashboard');
}

function handleAthleteSubmit(event) {
  event.preventDefault();

  enforceCoachGate();

  if (!hasCoachAccount()) {
    event.currentTarget.reset();
    navigate('onboarding');
    return;
  }

  if (state.athletes.length >= FREE_ATHLETE_LIMIT) {
    renderLimitMessage();
    navigate('dashboard');
    return;
  }

  const data = new FormData(event.currentTarget);
  const athlete = {
    id: createId(),
    firstName: clean(data.get('firstName')),
    lastName: clean(data.get('lastName')),
    sport: clean(data.get('sport')),
    level: clean(data.get('level')),
    goal: clean(data.get('goal')),
    vma: Number(data.get('vma')),
    comment: clean(data.get('comment')),
    sessions: [],
    createdAt: new Date().toISOString(),
  };

  if (!athlete.firstName || !athlete.lastName || !athlete.sport || !athlete.level || !athlete.goal || !isValidVma(athlete.vma)) {
    return;
  }

  state.athletes = state.athletes.concat(athlete);
  state.selectedAthleteId = athlete.id;
  saveState();
  event.currentTarget.reset();
  render();
  navigate('athleteDetail');
}

function handleSessionSubmit(event) {
  event.preventDefault();

  enforceCoachGate();

  if (!hasCoachAccount()) {
    event.currentTarget.reset();
    navigate('onboarding');
    return;
  }

  const athlete = getSelectedAthlete();
  if (!athlete) {
    navigate('dashboard');
    return;
  }

  const data = new FormData(event.currentTarget);
  const duration = Number(data.get('duration'));
  const vmaPercent = Number(data.get('vmaPercent'));
  const feeling = Number(data.get('feeling'));
  const session = {
    id: createId(),
    title: clean(data.get('title')),
    date: clean(data.get('date')),
    type: clean(data.get('type')),
    duration,
    intensity: clean(data.get('intensity')),
    vmaPercent: Number.isFinite(vmaPercent) && vmaPercent > 0 ? vmaPercent : null,
    feeling: Number.isFinite(feeling) && feeling > 0 ? feeling : null,
    comment: clean(data.get('comment')),
    createdAt: new Date().toISOString(),
  };

  if (
    !session.title ||
    !session.date ||
    !session.type ||
    !session.intensity ||
    !Number.isFinite(duration) ||
    duration <= 0 ||
    (session.vmaPercent !== null && !isValidVmaPercent(session.vmaPercent)) ||
    (session.feeling !== null && !isValidFeeling(session.feeling))
  ) {
    return;
  }

  state.athletes = state.athletes.map((item) => {
    if (item.id !== athlete.id) {
      return item;
    }

    return {
      ...item,
      sessions: sortSessions(item.sessions.concat(session)),
    };
  });

  saveState();
  event.currentTarget.reset();
  render();
  navigate('athleteDetail');
}

function openAthleteForm() {
  enforceCoachGate();

  if (!hasCoachAccount()) {
    navigate('onboarding');
    return;
  }

  if (state.athletes.length >= FREE_ATHLETE_LIMIT) {
    renderLimitMessage();
    navigate('dashboard');
    return;
  }

  document.querySelector('#athlete-form').reset();
  navigate('athleteForm');
}

function openSessionForm() {
  enforceCoachGate();

  if (!hasCoachAccount()) {
    navigate('onboarding');
    return;
  }

  if (!getSelectedAthlete()) {
    navigate('dashboard');
    return;
  }

  const form = document.querySelector('#session-form');
  form.reset();
  document.querySelector('#session-date').value = toInputDate(today);
  navigate('sessionForm');
}

function selectAthlete(id) {
  enforceCoachGate();

  if (!hasCoachAccount()) {
    navigate('onboarding');
    return;
  }

  state.selectedAthleteId = id;
  saveState();
  render();
  navigate('athleteDetail');
}

function navigate(pageName) {
  enforceCoachGate();

  const key = normalizePageName(pageName);
  const page = pages[key];
  if (!page) {
    return;
  }

  if (!hasCoachAccount() && key !== 'onboarding' && key !== 'coachForm') {
    navigate('onboarding');
    return;
  }

  Object.values(pages).forEach((item) => item.classList.remove('page-active'));
  page.classList.add('page-active');
  document.querySelector('#page-title').textContent = page.dataset.title || 'Coach';

  document.querySelectorAll('.nav-item').forEach((button) => {
    button.classList.toggle('nav-active', button.dataset.nav && normalizePageName(button.dataset.nav) === key);
  });
}

function normalizePageName(pageName) {
  const names = {
    onboarding: 'onboarding',
    'coach-form': 'coachForm',
    dashboard: 'dashboard',
    'athlete-form': 'athleteForm',
    'athlete-detail': 'athleteDetail',
    'session-form': 'sessionForm',
  };

  return names[pageName] || pageName;
}

function render() {
  enforceCoachGate();
  renderShell();
  renderDashboard();
  renderAthleteDetail();
}

function renderShell() {
  document.querySelector('#bottom-nav').hidden = !hasCoachAccount();
  document.querySelector('#header-eyebrow').textContent = hasCoachAccount() ? 'Espace coach' : 'Coach App';

  const athleteForm = document.querySelector('#athlete-form');
  const athleteFormDisabled = !hasCoachAccount();
  athleteForm.querySelectorAll('input, select, textarea, button').forEach((field) => {
    field.disabled = athleteFormDisabled;
  });

  document.querySelector('#open-athlete-form').disabled = athleteFormDisabled || state.athletes.length >= FREE_ATHLETE_LIMIT;
}

function renderDashboard() {
  const coachName = hasCoachAccount() ? `${state.coach.firstName} ${state.coach.lastName}` : '-';
  const club = hasCoachAccount() && state.coach.club ? state.coach.club : 'Compte local gratuit';

  document.querySelector('#coach-name').textContent = coachName;
  document.querySelector('#coach-meta').textContent = club;
  document.querySelector('#athlete-count').textContent = state.athletes.length;
  document.querySelector('#planned-count').textContent = getUpcomingSessions().length;
  document.querySelector('#missing-count').textContent = getMissingSessionReminders().length;
  renderLimitMessage();
  renderCoachOverview();
  renderCoachReminders();
  renderAthleteList();
}

function renderLimitMessage() {
  const message = document.querySelector('#limit-message');
  message.hidden = !hasCoachAccount() || state.athletes.length < FREE_ATHLETE_LIMIT;
}

function renderAthleteList() {
  const container = document.querySelector('#athlete-list');
  clearElement(container);

  if (!hasCoachAccount()) {
    container.append(createElement('p', 'empty-state', 'Cree d abord ton compte coach pour ajouter des athletes.'));
    return;
  }

  if (state.athletes.length === 0) {
    container.append(createElement('p', 'empty-state', 'Aucun athlete cree pour le moment.'));
    return;
  }

  state.athletes.forEach((athlete) => {
    const button = createElement('button', 'athlete-card', '');
    button.type = 'button';
    button.addEventListener('click', () => selectAthlete(athlete.id));

    const name = createElement('h3', '', `${athlete.firstName} ${athlete.lastName}`);
    const meta = createElement('p', 'card-meta', `${athlete.sport} - ${athlete.level}`);
    const goal = createElement('p', 'card-text', athlete.goal);
    const count = createElement('span', 'pill', `${athlete.sessions.length} seance${athlete.sessions.length > 1 ? 's' : ''}`);
    const vma = createElement('span', 'pill pill-muted', `VMA ${formatNumber(athlete.vma)} km/h`);

    button.append(name, meta, goal, createPillRow([count, vma]));
    container.append(button);
  });
}

function renderCoachOverview() {
  const container = document.querySelector('#coach-overview');
  clearElement(container);

  if (!hasCoachAccount() || state.athletes.length === 0) {
    container.append(createElement('p', 'empty-state', 'Ajoute un athlete pour afficher la vision globale.'));
    return;
  }

  state.athletes.forEach((athlete) => {
    const nextSession = getNextSession(athlete);
    const averageFeeling = getAverageFeeling(athlete);
    const card = createElement('article', 'overview-card', '');

    card.append(
      createElement('p', 'card-meta', `${athlete.sport} - ${athlete.level}`),
      createElement('h3', '', `${athlete.firstName} ${athlete.lastName}`),
      createElement(
        'p',
        'card-text',
        nextSession
          ? `Prochaine seance : ${formatDate(nextSession.date)} - ${nextSession.title}`
          : `Aucune seance prevue dans les ${MISSING_SESSION_DAYS} prochains jours.`,
      ),
      createPillRow([
        createElement('span', 'pill', `${athlete.sessions.length} seance${athlete.sessions.length > 1 ? 's' : ''}`),
        createElement('span', 'pill pill-muted', `VMA ${formatNumber(athlete.vma)} km/h`),
        createElement('span', 'pill pill-muted', averageFeeling ? `Ressenti moy. ${formatNumber(averageFeeling)}/10` : 'Ressenti non note'),
      ]),
    );

    container.append(card);
  });
}

function renderCoachReminders() {
  const container = document.querySelector('#coach-reminders');
  const reminders = getMissingSessionReminders();
  clearElement(container);

  if (!hasCoachAccount() || reminders.length === 0) {
    container.append(createElement('p', 'empty-state', 'Aucun rappel pour le moment.'));
    return;
  }

  reminders.forEach(({ athlete, lastSession }) => {
    const card = createElement('article', 'reminder-card', '');
    card.append(
      createElement('p', 'card-meta', 'Manque de seance'),
      createElement('h3', '', `${athlete.firstName} ${athlete.lastName}`),
      createElement(
        'p',
        'card-text',
        lastSession
          ? `Derniere seance le ${formatDate(lastSession.date)}. Planifier une nouvelle seance.`
          : `Aucune seance creee. Planifier une premiere seance.`,
      ),
    );
    container.append(card);
  });
}

function renderAthleteDetail() {
  if (!hasCoachAccount()) {
    clearAthleteDetail();
    return;
  }

  const athlete = getSelectedAthlete();
  if (!athlete) {
    clearAthleteDetail();
    return;
  }

  document.querySelector('#athlete-sport').textContent = athlete.sport;
  document.querySelector('#athlete-name').textContent = `${athlete.firstName} ${athlete.lastName}`;
  document.querySelector('#athlete-info').textContent = `${athlete.level} - Objectif : ${athlete.goal}`;
  document.querySelector('#athlete-comment').textContent = athlete.comment || 'Aucun commentaire.';

  renderAthleteMetrics(athlete);
  renderPaceGrid(athlete);
  renderAthleteCalendar(athlete);
  renderSessionList(athlete);
}

function renderAthleteMetrics(athlete) {
  const container = document.querySelector('#athlete-metrics');
  const averageFeeling = getAverageFeeling(athlete);
  clearElement(container);

  container.append(
    createMetric('VMA', `${formatNumber(athlete.vma)} km/h`),
    createMetric('Ressenti moyen', averageFeeling ? `${formatNumber(averageFeeling)}/10` : '-'),
  );
}

function renderPaceGrid(athlete) {
  const container = document.querySelector('#pace-grid');
  clearElement(container);

  VMA_PACE_PERCENTAGES.forEach((percent) => {
    const item = createElement('article', 'pace-card', '');
    item.append(
      createElement('span', '', `${percent}%`),
      createElement('strong', '', formatPace(calculatePace(athlete.vma, percent))),
      createElement('p', '', `${formatNumber((athlete.vma * percent) / 100)} km/h`),
    );
    container.append(item);
  });
}

function renderAthleteCalendar(athlete) {
  const year = today.getFullYear();
  const month = today.getMonth();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const firstDay = new Date(year, month, 1).getDay();
  const sessions = athlete.sessions.filter((session) => {
    const date = parseDate(session.date);
    return date && date.getFullYear() === year && date.getMonth() === month;
  });
  const byDay = groupBy(sessions, (session) => parseDate(session.date).getDate());
  const grid = document.querySelector('#calendar-grid');

  document.querySelector('#calendar-month').textContent = today.toLocaleDateString('fr-FR', {
    month: 'long',
    year: 'numeric',
  });
  document.querySelector('#session-count').textContent = `${sessions.length} seance${sessions.length > 1 ? 's' : ''}`;

  clearElement(grid);
  ['L', 'M', 'M', 'J', 'V', 'S', 'D'].forEach((day) => {
    grid.append(createElement('div', 'calendar-weekday', day));
  });

  const blanks = firstDay === 0 ? 6 : firstDay - 1;
  for (let index = 0; index < blanks; index += 1) {
    grid.append(createElement('div', 'calendar-day calendar-empty', ''));
  }

  for (let day = 1; day <= daysInMonth; day += 1) {
    const daySessions = byDay[day] || [];
    const cell = createElement('div', 'calendar-day', String(day));
    cell.classList.toggle('calendar-today', day === today.getDate());
    cell.classList.toggle('calendar-active', daySessions.length > 0);

    if (daySessions.length > 0) {
      cell.append(createElement('span', 'calendar-dot', String(daySessions.length)));
    }

    grid.append(cell);
  }
}

function renderSessionList(athlete) {
  const container = document.querySelector('#session-list');
  clearElement(container);

  if (athlete.sessions.length === 0) {
    container.append(createElement('p', 'empty-state', 'Aucune seance pour cet athlete.'));
    return;
  }

  sortSessions(athlete.sessions).forEach((session) => {
    const card = createElement('article', 'session-card', '');
    const paceText = session.vmaPercent ? ` - ${session.vmaPercent}% VMA (${formatPace(calculatePace(athlete.vma, session.vmaPercent))})` : '';
    const feelingText = session.feeling ? ` - Ressenti ${session.feeling}/10` : '';
    card.append(
      createElement('p', 'card-meta', `${formatDate(session.date)} - ${session.type} - ${session.intensity}`),
      createElement('h3', '', session.title),
      createElement('p', 'card-text', `${session.duration} min${paceText}${feelingText}${session.comment ? ` - ${session.comment}` : ''}`),
    );
    container.append(card);
  });
}

function loadState() {
  try {
    const value = localStorage.getItem(STORAGE_KEY);
    const parsed = value ? JSON.parse(value) : null;
    state.coach = normalizeCoach(parsed && parsed.coach);
    state.athletes = Array.isArray(parsed && parsed.athletes) ? parsed.athletes.map(normalizeAthlete).filter(Boolean) : [];
    state.selectedAthleteId = parsed && parsed.selectedAthleteId ? String(parsed.selectedAthleteId) : null;

    enforceCoachGate();
  } catch (error) {
    state.coach = null;
    state.athletes = [];
    state.selectedAthleteId = null;
  }
}

function enforceCoachGate() {
  state.coach = normalizeCoach(state.coach);

  if (!hasCoachAccount()) {
    state.coach = null;
    state.athletes = [];
    state.selectedAthleteId = null;
    return;
  }

  state.athletes = state.athletes.map(normalizeAthlete).filter(Boolean);

  const selectedExists = state.athletes.some((athlete) => athlete.id === state.selectedAthleteId);
  if (!selectedExists) {
    state.selectedAthleteId = state.athletes[0] ? state.athletes[0].id : null;
  }
}

function hasCoachAccount() {
  return Boolean(state.coach && state.coach.firstName && state.coach.lastName && state.coach.email);
}

function clearAthleteDetail() {
  document.querySelector('#athlete-sport').textContent = 'Athlete';
  document.querySelector('#athlete-name').textContent = '-';
  document.querySelector('#athlete-info').textContent = '-';
  document.querySelector('#athlete-comment').textContent = '';
  clearElement(document.querySelector('#athlete-metrics'));
  clearElement(document.querySelector('#pace-grid'));
  document.querySelector('#calendar-month').textContent = 'Mois';
  document.querySelector('#session-count').textContent = '0 seance';
  clearElement(document.querySelector('#calendar-grid'));
  clearElement(document.querySelector('#session-list'));
}

function saveState() {
  try {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        coach: state.coach,
        athletes: state.athletes,
        selectedAthleteId: state.selectedAthleteId,
      }),
    );
  } catch (error) {
    // Local MVP remains usable even if localStorage is unavailable.
  }
}

function normalizeCoach(coach) {
  if (!coach || typeof coach !== 'object') {
    return null;
  }

  const normalized = {
    id: String(coach.id || createId()),
    firstName: clean(coach.firstName),
    lastName: clean(coach.lastName),
    email: clean(coach.email),
    club: clean(coach.club),
    createdAt: String(coach.createdAt || new Date().toISOString()),
  };

  return normalized.firstName && normalized.lastName && normalized.email ? normalized : null;
}

function normalizeAthlete(athlete) {
  if (!athlete || typeof athlete !== 'object') {
    return null;
  }

  const normalized = {
    id: String(athlete.id || createId()),
    firstName: clean(athlete.firstName),
    lastName: clean(athlete.lastName),
    sport: clean(athlete.sport),
    level: clean(athlete.level),
    goal: clean(athlete.goal),
    vma: isValidVma(Number(athlete.vma)) ? Number(athlete.vma) : DEFAULT_VMA,
    comment: clean(athlete.comment),
    sessions: Array.isArray(athlete.sessions) ? athlete.sessions.map(normalizeSession).filter(Boolean) : [],
    createdAt: String(athlete.createdAt || new Date().toISOString()),
  };

  if (!normalized.firstName || !normalized.lastName || !normalized.sport || !normalized.level || !normalized.goal) {
    return null;
  }

  normalized.sessions = sortSessions(normalized.sessions);
  return normalized;
}

function normalizeSession(session) {
  if (!session || typeof session !== 'object') {
    return null;
  }

  const duration = Number(session.duration);
  const vmaPercent = Number(session.vmaPercent);
  const feeling = Number(session.feeling);
  const normalized = {
    id: String(session.id || createId()),
    title: clean(session.title),
    date: clean(session.date),
    type: clean(session.type),
    duration: Number.isFinite(duration) ? duration : 0,
    intensity: clean(session.intensity),
    vmaPercent: isValidVmaPercent(vmaPercent) ? vmaPercent : null,
    feeling: isValidFeeling(feeling) ? feeling : null,
    comment: clean(session.comment),
    createdAt: String(session.createdAt || new Date().toISOString()),
  };

  if (!normalized.title || !normalized.date || !normalized.type || !normalized.intensity || normalized.duration <= 0 || !parseDate(normalized.date)) {
    return null;
  }

  return normalized;
}

function getSelectedAthlete() {
  return state.athletes.find((athlete) => athlete.id === state.selectedAthleteId) || state.athletes[0] || null;
}

function getUpcomingSessions() {
  const todayTime = dateOnly(toInputDate(today));
  return state.athletes.flatMap((athlete) =>
    athlete.sessions
      .filter((session) => dateOnly(session.date) >= todayTime)
      .map((session) => ({ athlete, session })),
  );
}

function getNextSession(athlete) {
  const todayTime = dateOnly(toInputDate(today));
  return sortSessions(athlete.sessions).find((session) => dateOnly(session.date) >= todayTime) || null;
}

function getLastSession(athlete) {
  const pastSessions = sortSessions(athlete.sessions).filter((session) => dateOnly(session.date) < dateOnly(toInputDate(today)));
  return pastSessions[pastSessions.length - 1] || null;
}

function getMissingSessionReminders() {
  const todayTime = dateOnly(toInputDate(today));
  const limit = addDays(today, MISSING_SESSION_DAYS).getTime();

  return state.athletes
    .filter((athlete) => {
      const hasUpcomingSoon = athlete.sessions.some((session) => {
        const sessionTime = dateOnly(session.date);
        return sessionTime >= todayTime && sessionTime <= limit;
      });
      return !hasUpcomingSoon;
    })
    .map((athlete) => ({
      athlete,
      lastSession: getLastSession(athlete),
    }));
}

function getAverageFeeling(athlete) {
  const feelings = athlete.sessions.map((session) => session.feeling).filter(isValidFeeling);
  if (feelings.length === 0) {
    return null;
  }

  return feelings.reduce((total, feeling) => total + feeling, 0) / feelings.length;
}

function sortSessions(sessions) {
  return sessions.slice().sort((a, b) => a.date.localeCompare(b.date) || a.title.localeCompare(b.title));
}

function parseDate(value) {
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime()) ? null : date;
}

function dateOnly(value) {
  const date = parseDate(value);
  return date ? date.getTime() : 0;
}

function addDays(date, days) {
  const next = new Date(date);
  next.setDate(next.getDate() + days);
  next.setHours(0, 0, 0, 0);
  return next;
}

function formatDate(value) {
  const date = parseDate(value);
  if (!date) {
    return 'Date inconnue';
  }

  return date.toLocaleDateString('fr-FR', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  });
}

function toInputDate(date) {
  return date.toISOString().slice(0, 10);
}

function clean(value) {
  return String(value || '').trim();
}

function isValidVma(value) {
  return Number.isFinite(value) && value >= 5 && value <= 30;
}

function isValidVmaPercent(value) {
  return Number.isFinite(value) && value >= 40 && value <= 130;
}

function isValidFeeling(value) {
  return Number.isFinite(value) && value >= 1 && value <= 10;
}

function calculatePace(vma, percent) {
  if (!isValidVma(vma) || !isValidVmaPercent(percent)) {
    return null;
  }

  const speed = (vma * percent) / 100;
  return 60 / speed;
}

function formatPace(minutesPerKm) {
  if (!Number.isFinite(minutesPerKm) || minutesPerKm <= 0) {
    return '-';
  }

  const totalSeconds = Math.round(minutesPerKm * 60);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = String(totalSeconds % 60).padStart(2, '0');
  return `${minutes}'${seconds}/km`;
}

function formatNumber(value) {
  if (!Number.isFinite(value)) {
    return '-';
  }

  return new Intl.NumberFormat('fr-FR', {
    maximumFractionDigits: 1,
  }).format(value);
}

function createMetric(label, value) {
  const metric = createElement('article', 'metric-card', '');
  metric.append(createElement('span', '', label), createElement('strong', '', value));
  return metric;
}

function createPillRow(items) {
  const row = createElement('div', 'pill-row', '');
  row.append(...items);
  return row;
}

function createElement(tagName, className, textContent) {
  const element = document.createElement(tagName);
  if (className) {
    element.className = className;
  }
  element.textContent = textContent;
  return element;
}

function clearElement(element) {
  while (element.firstChild) {
    element.removeChild(element.firstChild);
  }
}

function createId() {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') {
    return window.crypto.randomUUID();
  }

  return `id-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

function groupBy(items, getKey) {
  return items.reduce((groups, item) => {
    const key = getKey(item);
    groups[key] = groups[key] || [];
    groups[key].push(item);
    return groups;
  }, {});
}
