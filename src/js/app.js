const STORAGE_KEY = 'coach.sessions.v1';

const state = {
  activePage: 'home',
  sessions: loadSessions(),
};

const pages = {
  home: document.querySelector('#home-page'),
  sessions: document.querySelector('#sessions-page'),
  calendar: document.querySelector('#calendar-page'),
};

const form = document.querySelector('#session-form');
const today = new Date();

document.querySelector('#session-date').value = toInputDate(today);

document.querySelectorAll('[data-nav]').forEach((button) => {
  button.addEventListener('click', () => navigate(button.dataset.nav));
});

form.addEventListener('submit', (event) => {
  event.preventDefault();

  const formData = new FormData(form);
  const session = {
    id: crypto.randomUUID(),
    title: String(formData.get('title')).trim(),
    date: String(formData.get('date')),
    type: String(formData.get('type')),
    duration: Number(formData.get('duration')),
    comment: String(formData.get('comment')).trim(),
    createdAt: new Date().toISOString(),
  };

  if (!session.title || !session.date || !session.duration) {
    return;
  }

  state.sessions = sortSessions([...state.sessions, session]);
  saveSessions(state.sessions);
  form.reset();
  document.querySelector('#session-date').value = toInputDate(today);
  render();
});

render();

function navigate(pageName) {
  state.activePage = pageName;

  Object.entries(pages).forEach(([name, page]) => {
    page.classList.toggle('page-active', name === pageName);
  });

  document.querySelectorAll('.nav-item').forEach((item) => {
    item.classList.toggle('nav-active', item.dataset.nav === pageName);
  });

  document.querySelector('#page-title').textContent = pages[pageName].dataset.title;
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
  const upcoming = state.sessions.filter((session) => dateOnly(session.date) >= dateOnly(toInputDate(today)));
  const nextSession = upcoming[0];
  const weekTotal = state.sessions.filter(isThisWeek).length;
  const totalDuration = state.sessions.reduce((total, session) => total + session.duration, 0);

  document.querySelector('#week-total').textContent = weekTotal;
  document.querySelector('#total-duration').textContent = `${totalDuration} min`;

  if (nextSession) {
    document.querySelector('#next-session-title').textContent = nextSession.title;
    document.querySelector('#next-session-meta').textContent = `${formatDate(nextSession.date)} · ${nextSession.type} · ${nextSession.duration} min`;
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
    const date = new Date(`${session.date}T00:00:00`);
    return date.getFullYear() === year && date.getMonth() === month;
  });
  const sessionsByDay = groupBy(monthSessions, (session) => new Date(`${session.date}T00:00:00`).getDate());
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
      const badge = createElement('span', 'day-badge', String(sessions.length));
      cell.append(badge);
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
    item.innerHTML = `
      <div>
        <p class="session-date">${formatDate(session.date)} · ${session.type}</p>
        <h3>${escapeHtml(session.title)}</h3>
        ${session.comment ? `<p class="session-comment">${escapeHtml(session.comment)}</p>` : ''}
      </div>
      <span class="duration">${session.duration} min</span>
    `;
    container.append(item);
  });
}

function loadSessions() {
  try {
    const value = localStorage.getItem(STORAGE_KEY);
    return value ? sortSessions(JSON.parse(value)) : [];
  } catch {
    return [];
  }
}

function saveSessions(sessions) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(sessions));
}

function sortSessions(sessions) {
  return sessions.sort((a, b) => a.date.localeCompare(b.date) || a.createdAt.localeCompare(b.createdAt));
}

function isThisWeek(session) {
  const sessionDate = new Date(`${session.date}T00:00:00`);
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
  return new Date(`${date}T00:00:00`).toLocaleDateString('fr-FR', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  });
}

function dateOnly(date) {
  return new Date(`${date}T00:00:00`).getTime();
}

function toInputDate(date) {
  return date.toISOString().slice(0, 10);
}

function createElement(tagName, className, textContent) {
  const element = document.createElement(tagName);
  element.className = className;
  element.textContent = textContent;
  return element;
}

function escapeHtml(value) {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
