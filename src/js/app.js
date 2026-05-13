const STORAGE_KEY = 'coach.sessions.v1';

const state = {
  activePage: 'home',
  sessions: [],
};

let pages = {};
let form;
let today;

initApp();

function initApp() {
  today = new Date();
  state.sessions = loadSessions();

  pages = {
    home: document.querySelector('#home-page'),
    sessions: document.querySelector('#sessions-page'),
    calendar: document.querySelector('#calendar-page'),
  };

  form = document.querySelector('#session-form');
  const dateInput = document.querySelector('#session-date');

  if (!form || !pages.home || !pages.sessions || !pages.calendar || !dateInput) {
    showStartupError();
    return;
  }

  dateInput.value = toInputDate(today);

  document.querySelectorAll('[data-nav]').forEach((button) => {
    button.addEventListener('click', () => navigate(button.dataset.nav));
  });

  form.addEventListener('submit', handleSessionSubmit);
  render();
}

function handleSessionSubmit(event) {
  event.preventDefault();

  const formData = new FormData(form);
  const duration = Number(formData.get('duration'));
  const session = {
    id: createId(),
    title: String(formData.get('title') || '').trim(),
    date: String(formData.get('date') || ''),
    type: String(formData.get('type') || 'Autre'),
    duration,
    comment: String(formData.get('comment') || '').trim(),
    createdAt: new Date().toISOString(),
  };

  if (!session.title || !session.date || !Number.isFinite(duration) || duration <= 0) {
    return;
  }

  state.sessions = sortSessions(state.sessions.concat(session));
  saveSessions(state.sessions);
  form.reset();
  document.querySelector('#session-date').value = toInputDate(today);
  render();
  navigate('sessions');
}

function navigate(pageName) {
  if (!pages[pageName]) {
    return;
  }

  state.activePage = pageName;

  Object.entries(pages).forEach(([name, page]) => {
    page.classList.toggle('page-active', name === pageName);
  });

  document.querySelectorAll('.nav-item').forEach((item) => {
    item.classList.toggle('nav-active', item.dataset.nav === pageName);
  });

  document.querySelector('#page-title').textContent = pages[pageName].dataset.title || 'Accueil';
}

function render() {
  renderHeader();
  renderHome();
  renderSessions();
  renderCalendar();
}

function renderHeader() {
  const count = state.sessions.length;
  document.querySelector('#session-count').textContent = count;
  document.querySelector('.week-pill span:last-child').textContent = count > 1 ? 'seances' : 'seance';
}

function renderHome() {
  const todayTime = dateOnly(toInputDate(today));
  const upcoming = state.sessions.filter((session) => dateOnly(session.date) >= todayTime);
  const nextSession = upcoming[0];
  const weekTotal = state.sessions.filter(isThisWeek).length;
  const totalDuration = state.sessions.reduce((total, session) => total + session.duration, 0);

  document.querySelector('#week-total').textContent = weekTotal;
  document.querySelector('#total-duration').textContent = `${totalDuration} min`;

  if (nextSession) {
    document.querySelector('#next-session-title').textContent = nextSession.title;
    document.querySelector('#next-session-meta').textContent = `${formatDate(nextSession.date)} - ${nextSession.type} - ${nextSession.duration} min`;
  } else {
    document.querySelector('#next-session-title').textContent = 'Aucune seance prevue';
    document.querySelector('#next-session-meta').textContent = 'Ajoute ta premiere seance pour construire ton planning.';
  }

  renderSessionList(document.querySelector('#upcoming-list'), upcoming.slice(0, 3), 'Aucune seance a venir.');
}

function renderSessions() {
  renderSessionList(document.querySelector('#sessions-list'), state.sessions, 'Aucune seance enregistree.');
}

function renderCalendar() {
  const year = today.getFullYear();
  const month = today.getMonth();
  const days = new Date(year, month + 1, 0).getDate();
  const firstDay = new Date(year, month, 1).getDay();
  const monthSessions = state.sessions.filter((session) => {
    const sessionDate = parseSessionDate(session.date);
    return sessionDate && sessionDate.getFullYear() === year && sessionDate.getMonth() === month;
  });
  const sessionsByDay = groupBy(monthSessions, (session) => parseSessionDate(session.date).getDate());
  const grid = document.querySelector('#calendar-grid');

  document.querySelector('#calendar-month').textContent = today.toLocaleDateString('fr-FR', {
    month: 'long',
    year: 'numeric',
  });
  document.querySelector('#calendar-total').textContent = `${monthSessions.length} ${monthSessions.length > 1 ? 'seances' : 'seance'}`;

  grid.innerHTML = '';
  ['L', 'M', 'M', 'J', 'V', 'S', 'D'].forEach((day) => {
    grid.append(createElement('div', 'calendar-weekday', day));
  });

  const leadingBlanks = firstDay === 0 ? 6 : firstDay - 1;
  for (let index = 0; index < leadingBlanks; index += 1) {
    grid.append(createElement('div', 'calendar-day is-empty', ''));
  }

  for (let day = 1; day <= days; day += 1) {
    const sessions = sessionsByDay[day] || [];
    const cell = createElement('div', 'calendar-day', String(day));
    cell.classList.toggle('has-session', sessions.length > 0);
    cell.classList.toggle('is-today', day === today.getDate());

    if (sessions.length > 0) {
      cell.append(createElement('span', 'day-badge', String(sessions.length)));
    }

    grid.append(cell);
  }

  renderSessionList(document.querySelector('#calendar-list'), monthSessions, 'Aucune seance ce mois-ci.');
}

function renderSessionList(container, sessions, emptyMessage) {
  container.innerHTML = '';

  if (sessions.length === 0) {
    container.append(createElement('p', 'empty-state', emptyMessage));
    return;
  }

  sessions.forEach((session) => {
    const item = createElement('article', 'session-card');
    const content = createElement('div', 'session-content', '');
    const meta = createElement('p', 'session-date', `${formatDate(session.date)} - ${session.type}`);
    const title = createElement('h3', '', session.title);
    const duration = createElement('span', 'duration', `${session.duration} min`);

    content.append(meta, title);

    if (session.comment) {
      content.append(createElement('p', 'session-comment', session.comment));
    }

    item.append(content, duration);
    container.append(item);
  });
}

function loadSessions() {
  try {
    const value = localStorage.getItem(STORAGE_KEY);
    const parsed = value ? JSON.parse(value) : [];

    if (!Array.isArray(parsed)) {
      return [];
    }

    return sortSessions(parsed.map(normalizeSession).filter(Boolean));
  } catch (error) {
    return [];
  }
}

function saveSessions(sessions) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(sessions));
  } catch (error) {
    // Keep the UI usable even if storage is temporarily unavailable.
  }
}

function sortSessions(sessions) {
  return sessions.sort((a, b) => {
    const dateCompare = String(a.date).localeCompare(String(b.date));
    if (dateCompare !== 0) {
      return dateCompare;
    }

    return String(a.createdAt).localeCompare(String(b.createdAt));
  });
}

function isThisWeek(session) {
  const sessionDate = parseSessionDate(session.date);
  if (!sessionDate) {
    return false;
  }

  const current = new Date(today);
  const day = current.getDay() || 7;
  const weekStart = new Date(current);
  weekStart.setDate(current.getDate() - day + 1);
  weekStart.setHours(0, 0, 0, 0);

  const weekEnd = new Date(weekStart);
  weekEnd.setDate(weekStart.getDate() + 6);
  weekEnd.setHours(23, 59, 59, 999);

  return sessionDate >= weekStart && sessionDate <= weekEnd;
}

function groupBy(items, getKey) {
  return items.reduce((groups, item) => {
    const key = getKey(item);
    groups[key] = groups[key] || [];
    groups[key].push(item);
    return groups;
  }, {});
}

function formatDate(date) {
  const parsedDate = parseSessionDate(date);
  if (!parsedDate) {
    return 'Date inconnue';
  }

  return parsedDate.toLocaleDateString('fr-FR', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  });
}

function dateOnly(date) {
  const parsedDate = parseSessionDate(date);
  return parsedDate ? parsedDate.getTime() : 0;
}

function parseSessionDate(date) {
  const parsedDate = new Date(`${date}T00:00:00`);
  return Number.isNaN(parsedDate.getTime()) ? null : parsedDate;
}

function toInputDate(date) {
  return date.toISOString().slice(0, 10);
}

function createElement(tagName, className, textContent) {
  const element = document.createElement(tagName);

  if (className) {
    element.className = className;
  }

  element.textContent = textContent;
  return element;
}

function createId() {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') {
    return window.crypto.randomUUID();
  }

  return `session-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

function normalizeSession(session) {
  if (!session || typeof session !== 'object') {
    return null;
  }

  const duration = Number(session.duration);
  const normalized = {
    id: String(session.id || createId()),
    title: String(session.title || '').trim(),
    date: String(session.date || ''),
    type: String(session.type || 'Autre'),
    duration: Number.isFinite(duration) ? duration : 0,
    comment: String(session.comment || '').trim(),
    createdAt: String(session.createdAt || new Date().toISOString()),
  };

  if (!normalized.title || !normalized.date || normalized.duration <= 0 || !parseSessionDate(normalized.date)) {
    return null;
  }

  return normalized;
}

function showStartupError() {
  document.body.innerHTML = '<main class="app-shell"><h1>Accueil</h1><p class="empty-state">Impossible de charger l interface.</p></main>';
}
