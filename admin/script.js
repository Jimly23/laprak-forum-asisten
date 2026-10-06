const API_CANDIDATES = [
  window.KUMPULTUGAS_API_URL,
  'http://127.0.0.1:8001/api/v1'
  // 'https://api.asisten.teraspelajar.com/api/v1'
].filter(Boolean);
let API_BASE_URL = API_CANDIDATES[0];
let apiResolved = false;
const AUTH_TOKEN_KEY = 'kumpultugas-admin-token';

const data = { classes: [], courses: [], admins: [], submissions: [], grades: [], gradeGroups: [] };
let currentUser = null;
let editing = { type: null, id: null };
let submissionTotal = 0;
let gradeTotal = 0;
let activeSubmission = null;
let activePdfUrl = null;

const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => [...document.querySelectorAll(selector)];

function escapeHtml(value) {
  const div = document.createElement('div');
  div.textContent = value ?? '';
  return div.innerHTML;
}

function getOptionLabel(item) {
  const code = String(item.code ?? '').trim();
  const name = String(item.name ?? '').trim();

  if (!name || code.localeCompare(name, undefined, { sensitivity: 'accent' }) === 0) return code || name;
  return `${code} — ${name}`;
}

function showToast(message) {
  const toast = $('#toast');
  toast.textContent = message;
  toast.classList.remove('hidden');
  window.clearTimeout(showToast.timer);
  showToast.timer = window.setTimeout(() => toast.classList.add('hidden'), 3000);
}

function setCrudLoading(isLoading, message = 'Memproses perubahan...') {
  $('#crudLoadingText').textContent = message;
  $('#crudLoading').classList.toggle('hidden', !isLoading);
  $('#crudLoading').classList.toggle('flex', isLoading);
}

function showSubmissionLoading() {
  $('#submissionCount').textContent = 'Memuat data...';
  $('#submissionRows').innerHTML = '<tr><td colspan="7" class="px-5 py-12 text-center"><span class="loading-spinner text-navy" aria-hidden="true"></span><span class="ml-2 text-sm font-semibold text-slate-400">Memuat daftar tugas...</span></td></tr>';
}

function formatDate(value) {
  if (!value) return '-';
  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
  }).format(new Date(value));
}

function formatScore(value) {
  if (value === null || value === undefined || value === '') return '—';
  return Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 });
}

function roleLabel(role) {
  return role === 'super_admin' ? 'Super admin' : 'Anggota';
}

async function apiRequest(path, options = {}) {
  await discoverApiBaseUrl();
  const headers = { Accept: 'application/json', ...options.headers };
  const token = sessionStorage.getItem(AUTH_TOKEN_KEY);
  if (token) headers.Authorization = `Bearer ${token}`;
  if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';

  let response;
  try {
    response = await fetch(`${API_BASE_URL}${path}`, { ...options, headers });
  } catch {
    throw new Error('Tidak dapat terhubung ke server. Pastikan backend Laravel sedang berjalan.');
  }

  const result = await response.json().catch(() => ({}));
  if (response.status === 401) {
    sessionStorage.removeItem(AUTH_TOKEN_KEY);
    showLogin();
    throw new Error('Sesi telah berakhir. Silakan login kembali.');
  }
  if (!response.ok) {
    const validationMessage = result.errors ? Object.values(result.errors)[0]?.[0] : null;
    throw new Error(validationMessage || result.message || 'Permintaan tidak dapat diproses.');
  }
  return result;
}

async function discoverApiBaseUrl() {
  if (apiResolved) return;
  for (const candidate of API_CANDIDATES) {
    try {
      const response = await fetch(`${candidate}/classes`, { headers: { Accept: 'application/json' } });
      if (!response.ok) continue;
      const result = await response.json();
      if (result.success === true && Array.isArray(result.data)) {
        API_BASE_URL = candidate;
        apiResolved = true;
        return;
      }
    } catch {
      // Coba kandidat port berikutnya.
    }
  }
  throw new Error('API KumpulTugas tidak ditemukan. Pastikan backend Laravel sedang berjalan.');
}

function showLogin() {
  $('#appView').classList.add('hidden');
  $('#loginView').classList.remove('hidden');
}

function showApp() {
  $('#loginView').classList.add('hidden');
  $('#appView').classList.remove('hidden');
  const email = currentUser?.email || 'Admin';
  $('#currentAdminEmail').textContent = email;
  $('#currentAdminRole').textContent = roleLabel(currentUser?.role);
  $('#currentAdminInitials').textContent = email.slice(0, 2).toUpperCase();
  const adminNavigation = $('[data-page="admins"]');
  adminNavigation.classList.toggle('hidden', currentUser?.role !== 'super_admin');
}

async function loadReferenceData() {
  const [classResult, courseResult] = await Promise.all([
    apiRequest('/classes'),
    apiRequest('/courses')
  ]);
  data.classes = classResult.data;
  data.courses = courseResult.data;
  renderClasses();
  renderCourses();
  renderFilterOptions();
}

function currentSubmissionQuery() {
  const params = new URLSearchParams({ per_page: '100' });
  const search = $('#searchStudent').value.trim();
  if (search) params.set('search', search);
  if ($('#filterClass').value) params.set('class_id', $('#filterClass').value);
  if ($('#filterCourse').value) params.set('course_id', $('#filterCourse').value);
  if ($('#filterMeeting').value) params.set('meeting', $('#filterMeeting').value);
  return params.toString();
}

async function loadSubmissions() {
  showSubmissionLoading();
  try {
    const result = await apiRequest(`/submissions?${currentSubmissionQuery()}`);
    data.submissions = result.data;
    submissionTotal = result.meta.total;
    renderSubmissions();
  } catch (error) {
    $('#submissionCount').textContent = 'Gagal memuat tugas';
    $('#submissionRows').innerHTML = `<tr><td colspan="7" class="px-5 py-12 text-center text-sm font-semibold text-red-500">${escapeHtml(error.message)}</td></tr>`;
    throw error;
  }
}

async function loadAdmins() {
  if (currentUser?.role !== 'super_admin') return;
  const result = await apiRequest('/admins');
  data.admins = result.data;
  renderAdmins();
}

function currentGradeQuery() {
  const params = new URLSearchParams();
  const search = $('#searchGradeStudent').value.trim();
  if (search) params.set('search', search);
  const group = data.gradeGroups.find((item) => item.key === $('#gradeFilterGroup').value);
  if (group) {
    params.set('class_id', group.class.id);
    params.set('course_id', group.course.id);
  }
  return params.toString();
}

async function loadGradeGroups() {
  const result = await apiRequest('/grades/groups');
  data.gradeGroups = result.data;
  renderGradeGroupOptions();
}

function renderGradeGroupOptions() {
  const selected = $('#gradeFilterGroup').value;
  $('#gradeFilterGroup').innerHTML = '<option value="">Pilih kelas dan mata kuliah</option>' + data.gradeGroups.map((group) => `<option value="${escapeHtml(group.key)}">${escapeHtml(getOptionLabel(group.class))} — ${escapeHtml(getOptionLabel(group.course))}</option>`).join('');
  $('#gradeFilterGroup').value = data.gradeGroups.some((group) => group.key === selected) ? selected : '';
}

async function loadGrades() {
  $('#gradeCount').textContent = 'Memuat data...';
  $('#gradeRows').innerHTML = '<tr><td colspan="18" class="px-5 py-12 text-center"><span class="loading-spinner text-navy" aria-hidden="true"></span><span class="ml-2 text-sm font-semibold text-slate-400">Memuat rekap nilai...</span></td></tr>';
  try {
    const groupKey = $('#gradeFilterGroup').value;
    if (!groupKey) {
      data.grades = [];
      gradeTotal = 0;
      renderGrades('Pilih kombinasi kelas dan mata kuliah untuk melihat rekap nilai.');
      return;
    }
    const query = currentGradeQuery();
    const result = await apiRequest(`/grades${query ? `?${query}` : ''}`);
    data.grades = result.data;
    gradeTotal = result.meta.total;
    renderGrades();
  } catch (error) {
    $('#gradeCount').textContent = 'Gagal memuat nilai';
    $('#gradeRows').innerHTML = `<tr><td colspan="18" class="px-5 py-12 text-center text-sm font-semibold text-red-500">${escapeHtml(error.message)}</td></tr>`;
    throw error;
  }
}

async function loadAppData() {
  await loadReferenceData();
  await loadGradeGroups();
  await Promise.all([loadSubmissions(), loadGrades(), loadAdmins()]);
}

function renderFilterOptions() {
  const selectedClass = $('#filterClass').value;
  const selectedCourse = $('#filterCourse').value;
  const selectedMeeting = $('#filterMeeting').value;
  $('#filterClass').innerHTML = '<option value="">Semua kelas</option>' + data.classes.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(getOptionLabel(item))}</option>`).join('');
  $('#filterCourse').innerHTML = '<option value="">Semua mata kuliah</option>' + data.courses.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(getOptionLabel(item))}</option>`).join('');
  $('#filterMeeting').innerHTML = '<option value="">Semua pertemuan</option>' + Array.from({ length: 14 }, (_, index) => `<option value="${index + 1}">Pertemuan ${index + 1}</option>`).join('');
  $('#filterClass').value = selectedClass;
  $('#filterCourse').value = selectedCourse;
  $('#filterMeeting').value = selectedMeeting;
}

function renderSubmissions() {
  $('#submissionCount').textContent = `${submissionTotal} tugas ditemukan`;
  $('#submissionRows').innerHTML = data.submissions.length ? data.submissions.map((item) => `<tr class="transition hover:bg-blue-50/40"><td class="px-5 py-4"><p class="font-bold text-ink">${escapeHtml(item.student_name)}</p><p class="mt-0.5 text-xs text-slate-400">${escapeHtml(item.nim)}</p></td><td class="px-5 py-4"><span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">${escapeHtml(item.class.code)}</span></td><td class="px-5 py-4"><p class="font-semibold text-slate-600">${escapeHtml(item.course.name)}</p><p class="mt-0.5 text-xs text-slate-400">${escapeHtml(item.course.code)}</p></td><td class="px-5 py-4"><span class="whitespace-nowrap rounded-lg bg-orange-50 px-2.5 py-1 text-xs font-bold text-orange-600">Ke-${item.meeting}</span></td><td class="px-5 py-4"><span class="inline-flex items-center gap-2 text-xs font-bold text-navy"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-50 text-[9px]">PDF</span>${escapeHtml(item.file.name)}</span></td><td class="whitespace-nowrap px-5 py-4 text-xs text-slate-500">${formatDate(item.submitted_at)}</td><td class="px-5 py-4 text-right"><div class="flex items-center justify-end gap-2">${item.grade ? `<span class="rounded-lg bg-emerald-50 px-2.5 py-2 text-xs font-extrabold text-emerald-600">${formatScore(item.grade.score)}</span>` : ''}<button class="whitespace-nowrap rounded-lg bg-navy px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-blue-900" data-view-task="${item.id}">Lihat & nilai</button></div></td></tr>`).join('') : '<tr><td colspan="7" class="px-5 py-12 text-center text-sm text-slate-400">Tidak ada tugas yang sesuai dengan filter.</td></tr>';
}

function renderGrades(emptyMessage = 'Belum ada nilai yang sesuai dengan filter.') {
  $('#gradeHeaders').innerHTML = '<th class="w-[9.5rem] px-4 py-3.5">NIM</th><th class="w-[14rem] px-4 py-3.5">Nama</th><th class="px-4 py-3.5">Kelas</th><th class="px-4 py-3.5">Mata kuliah</th>' + Array.from({ length: 14 }, (_, index) => `<th class="px-3 py-3.5 text-center">Pertemuan ${index + 1}</th>`).join('');
  $('#gradeCount').textContent = `${gradeTotal} baris nilai ditemukan`;
  $('#gradeRows').innerHTML = data.grades.length ? data.grades.map((item) => `<tr class="bg-white hover:bg-blue-50/40"><td class="w-[9.5rem] px-4 py-4 font-extrabold text-ink">${escapeHtml(item.nim)}</td><td class="w-[14rem] px-4 py-4 font-semibold text-slate-700">${escapeHtml(item.student_name)}</td><td class="whitespace-nowrap px-4 py-4"><span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">${escapeHtml(item.class.code)}</span></td><td class="min-w-[14rem] px-4 py-4"><p class="font-semibold text-slate-700">${escapeHtml(item.course.name)}</p><p class="text-xs text-slate-400">${escapeHtml(item.course.code)}</p></td>${Array.from({ length: 14 }, (_, index) => { const score = item.meetings[String(index + 1)] ?? item.meetings[index + 1]; return `<td class="px-3 py-4 text-center"><span class="${score === null || score === undefined ? 'text-slate-300' : 'font-extrabold text-navy'}">${formatScore(score)}</span></td>`; }).join('')}</tr>`).join('') : `<tr><td colspan="18" class="px-5 py-12 text-center text-sm text-slate-400">${escapeHtml(emptyMessage)}</td></tr>`;
}

function renderClasses() {
  $('#classCount').textContent = `${data.classes.length} kelas terdaftar`;
  $('#classRows').innerHTML = data.classes.length ? data.classes.map((item) => `<tr class="hover:bg-blue-50/40"><td class="px-5 py-4 font-semibold text-ink">${escapeHtml(item.name)}</td><td class="px-5 py-4 text-right"><button class="mr-3 text-xs font-bold text-navy hover:text-coral" data-edit="class" data-id="${item.id}">Edit</button><button class="text-xs font-bold text-red-400 hover:text-red-600" data-delete="class" data-id="${item.id}">Hapus</button></td></tr>`).join('') : '<tr><td colspan="2" class="px-5 py-12 text-center text-sm text-slate-400">Belum ada kelas.</td></tr>';
}

function renderCourses() {
  $('#courseCount').textContent = `${data.courses.length} mata kuliah terdaftar`;
  $('#courseRows').innerHTML = data.courses.length ? data.courses.map((item) => `<tr class="hover:bg-blue-50/40"><td class="px-5 py-4 font-semibold text-ink">${escapeHtml(item.name)}</td><td class="px-5 py-4 text-right"><button class="mr-3 text-xs font-bold text-navy hover:text-coral" data-edit="course" data-id="${item.id}">Edit</button><button class="text-xs font-bold text-red-400 hover:text-red-600" data-delete="course" data-id="${item.id}">Hapus</button></td></tr>`).join('') : '<tr><td colspan="2" class="px-5 py-12 text-center text-sm text-slate-400">Belum ada mata kuliah.</td></tr>';
}

function renderAdmins() {
  $('#adminCount').textContent = `${data.admins.length} akun terdaftar`;
  $('#adminRows').innerHTML = data.admins.map((item) => `<div class="flex items-center gap-3 px-5 py-4"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${item.role === 'super_admin' ? 'bg-coral text-white' : 'bg-blue-100 text-navy'} text-sm font-extrabold">${escapeHtml(item.email.slice(0, 2).toUpperCase())}</span><div class="min-w-0 flex-1"><p class="truncate text-sm font-bold text-ink">${escapeHtml(item.email)}</p><p class="mt-0.5 text-xs text-slate-400">${roleLabel(item.role)} · ${formatDate(item.created_at)}</p></div>${item.role === 'member' ? `<button class="text-xs font-bold text-red-400 hover:text-red-600" data-delete-admin="${item.id}">Hapus</button>` : '<span class="rounded-full bg-orange-50 px-2.5 py-1 text-[10px] font-bold text-orange-500">Pemilik</span>'}</div>`).join('');
}

function showPage(page) {
  if (page === 'admins' && currentUser?.role !== 'super_admin') return;
  $$('.page-section').forEach((section) => section.classList.toggle('hidden', section.dataset.section !== page));
  $$('.nav-item').forEach((item) => item.classList.toggle('active', item.dataset.page === page));
  const titles = { submissions: ['Daftar tugas', 'Daftar tugas mahasiswa'], grades: ['Nilai', 'Rekap nilai mahasiswa'], classes: ['Kelas', 'Kelola kelas'], courses: ['Mata kuliah', 'Kelola mata kuliah'], admins: ['Akun admin', 'Akun admin'] };
  $('#breadcrumbPage').textContent = titles[page][0];
  $('#pageTitle').textContent = titles[page][1];
  closeSidebar();
}

function openDataModal(type, mode, id = null) {
  editing = { type, id };
  const collection = type === 'class' ? data.classes : data.courses;
  const item = mode === 'edit' ? collection.find((entry) => String(entry.id) === String(id)) : null;
  $('#dataModalEyebrow').textContent = mode === 'edit' ? 'Perbarui data' : 'Data baru';
  $('#dataModalTitle').textContent = `${mode === 'edit' ? 'Edit' : 'Tambah'} ${type === 'class' ? 'kelas' : 'mata kuliah'}`;
  $('#dataNameLabel').innerHTML = `${type === 'class' ? 'Nama kelas' : 'Nama mata kuliah'} <span class="text-coral">*</span>`;
  $('#dataName').value = item?.name || '';
  $('#dataFormError').classList.add('hidden');
  $('#dataModal').classList.remove('hidden');
  $('#dataModal').classList.add('flex');
  $('#dataName').focus();
}

function closeDataModal() {
  $('#dataModal').classList.add('hidden');
  $('#dataModal').classList.remove('flex');
}

function closeSidebar() {
  $('#sidebar').classList.remove('open');
  $('#sidebarBackdrop').classList.add('hidden');
}

async function fetchProtectedBlob(path, accept) {
  await discoverApiBaseUrl();
  const token = sessionStorage.getItem(AUTH_TOKEN_KEY);
  const response = await fetch(`${API_BASE_URL}${path}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: accept }
  });
  if (response.status === 401) {
    sessionStorage.removeItem(AUTH_TOKEN_KEY);
    showLogin();
    throw new Error('Sesi telah berakhir. Silakan login kembali.');
  }
  if (!response.ok) {
    const result = await response.json().catch(() => ({}));
    const validationMessage = result.errors ? Object.values(result.errors)[0]?.[0] : null;
    throw new Error(validationMessage || result.message || 'File tidak dapat diproses.');
  }
  const disposition = response.headers.get('Content-Disposition') || '';
  const encodedFilename = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
  const plainFilename = disposition.match(/filename="?([^";]+)"?/i)?.[1];
  let filename = plainFilename || '';
  if (encodedFilename) {
    try { filename = decodeURIComponent(encodedFilename); } catch { filename = encodedFilename; }
  }
  return { blob: await response.blob(), filename };
}

function closeSubmissionReview() {
  activeSubmission = null;
  $('#submissionPdf').src = 'about:blank';
  if (activePdfUrl) URL.revokeObjectURL(activePdfUrl);
  activePdfUrl = null;
  $('#reviewModal').classList.add('hidden');
  $('#reviewModal').classList.remove('flex');
  document.body.classList.remove('review-open');
}

async function openSubmissionReview(id) {
  let submission = data.submissions.find((item) => String(item.id) === String(id));
  try {
    if (!submission) submission = (await apiRequest(`/submissions/${id}`)).data;
    activeSubmission = submission;
    $('#reviewTitle').textContent = 'Pemeriksaan tugas';
    $('#reviewContext').textContent = `${getOptionLabel(submission.class)} · ${getOptionLabel(submission.course)} · Pertemuan ${submission.meeting}`;
    $('#gradeStudentInfo').textContent = `${submission.student_name} (${submission.nim})`;
    $('#gradeMeetingInfo').textContent = `${getOptionLabel(submission.course)} · ${getOptionLabel(submission.class)} · Pertemuan ${submission.meeting}`;
    $('#gradeScore').value = submission.grade?.score ?? '';
    $('#gradeFormError').classList.add('hidden');
    $('#saveGradeLabel').textContent = submission.grade ? 'Perbarui nilai' : 'Simpan nilai';
    $('#reviewModal').classList.remove('hidden');
    $('#reviewModal').classList.add('flex');
    document.body.classList.add('review-open');
    $('#pdfLoading').classList.remove('hidden');
    $('#pdfLoading').classList.add('flex');
    $('#pdfLoading').innerHTML = '<span class="loading-spinner mr-3" aria-hidden="true"></span>Memuat PDF...';

    const requestedId = submission.id;
    const result = await fetchProtectedBlob(`/submissions/${submission.id}/file`, 'application/pdf');
    if (activeSubmission?.id !== requestedId) return;
    activePdfUrl = URL.createObjectURL(result.blob);
    $('#submissionPdf').addEventListener('load', () => {
      $('#pdfLoading').classList.add('hidden');
      $('#pdfLoading').classList.remove('flex');
    }, { once: true });
    $('#submissionPdf').src = activePdfUrl;
    window.setTimeout(() => $('#gradeScore').focus(), 100);
  } catch (error) {
    if ($('#reviewModal').classList.contains('hidden')) showToast(error.message);
    else $('#pdfLoading').innerHTML = `<div class="max-w-md px-6 text-center"><p class="text-red-300">${escapeHtml(error.message)}</p><p class="mt-2 text-xs font-medium text-slate-400">Nilai masih dapat diisi melalui panel di bawah.</p></div>`;
  }
}

async function downloadProtectedFile(path, fallbackFilename, button) {
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = 'Menyiapkan file...';
  try {
    const result = await fetchProtectedBlob(path, 'application/octet-stream');
    const url = URL.createObjectURL(result.blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = result.filename || fallbackFilename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    showToast('File berhasil dibuat dan diunduh.');
  } catch (error) {
    showToast(error.message);
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
}

$('#loginForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  $('#loginError').classList.add('hidden');
  try {
    const result = await apiRequest('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email: $('#loginEmail').value.trim(), password: $('#loginPassword').value })
    });
    sessionStorage.setItem(AUTH_TOKEN_KEY, result.data.token);
    currentUser = result.data.user;
    showApp();
    await loadAppData();
    showPage('submissions');
  } catch (error) {
    $('#loginError').textContent = error.message;
    $('#loginError').classList.remove('hidden');
  }
});

$('#logoutButton').addEventListener('click', async () => {
  try { await apiRequest('/auth/logout', { method: 'POST' }); } catch { /* local logout tetap dilakukan */ }
  sessionStorage.removeItem(AUTH_TOKEN_KEY);
  currentUser = null;
  $('#loginForm').reset();
  showLogin();
});

$('#mainNav').addEventListener('click', (event) => {
  const button = event.target.closest('[data-page]');
  if (button) showPage(button.dataset.page);
});
$('#menuButton').addEventListener('click', () => { $('#sidebar').classList.add('open'); $('#sidebarBackdrop').classList.remove('hidden'); });
$('#sidebarBackdrop').addEventListener('click', closeSidebar);

let filterTimer;
['searchStudent', 'filterClass', 'filterCourse', 'filterMeeting'].forEach((id) => $(`#${id}`).addEventListener('input', () => {
  window.clearTimeout(filterTimer);
  filterTimer = window.setTimeout(() => loadSubmissions().catch((error) => showToast(error.message)), 250);
}));
$('#clearFilters').addEventListener('click', () => {
  $('#searchStudent').value = '';
  $('#filterClass').value = '';
  $('#filterCourse').value = '';
  $('#filterMeeting').value = '';
  loadSubmissions().catch((error) => showToast(error.message));
});

let gradeFilterTimer;
['searchGradeStudent', 'gradeFilterGroup'].forEach((id) => $(`#${id}`).addEventListener('input', () => {
  window.clearTimeout(gradeFilterTimer);
  gradeFilterTimer = window.setTimeout(() => loadGrades().catch((error) => showToast(error.message)), 250);
}));
$('#clearGradeFilters').addEventListener('click', () => {
  $('#searchGradeStudent').value = '';
  $('#gradeFilterGroup').value = '';
  loadGrades().catch((error) => showToast(error.message));
});

$('#exportGrades').addEventListener('click', () => {
  const group = data.gradeGroups.find((item) => item.key === $('#gradeFilterGroup').value);
  if (!group) {
    showToast('Pilih satu kombinasi kelas dan mata kuliah untuk export Excel.');
    return;
  }
  const params = new URLSearchParams({ class_id: group.class.id, course_id: group.course.id });
  const search = $('#searchGradeStudent').value.trim();
  if (search) params.set('search', search);
  downloadProtectedFile(`/grades/export?${params}`, 'Nilai.xlsx', $('#exportGrades'));
});

$('#exportAllGrades').addEventListener('click', () => {
  downloadProtectedFile('/grades/export-all', 'Rekap_Nilai.zip', $('#exportAllGrades'));
});

document.addEventListener('click', async (event) => {
  const addButton = event.target.closest('.open-data-modal');
  if (addButton) openDataModal(addButton.dataset.type, addButton.dataset.mode);
  const editButton = event.target.closest('[data-edit]');
  if (editButton) openDataModal(editButton.dataset.edit, 'edit', editButton.dataset.id);

  const deleteButton = event.target.closest('[data-delete]');
  if (deleteButton) {
    const type = deleteButton.dataset.delete;
    const collection = type === 'class' ? data.classes : data.courses;
    const item = collection.find((entry) => String(entry.id) === String(deleteButton.dataset.id));
    if (item && window.confirm(`Hapus ${item.name}?`)) {
      setCrudLoading(true, 'Menghapus data...');
      try {
        await apiRequest(`/${type === 'class' ? 'classes' : 'courses'}/${item.id}`, { method: 'DELETE' });
        await loadReferenceData();
        showToast('Data berhasil dihapus.');
      } catch (error) { showToast(error.message); }
      finally { setCrudLoading(false); }
    }
  }

  const deleteAdmin = event.target.closest('[data-delete-admin]');
  if (deleteAdmin && window.confirm('Hapus akun anggota ini?')) {
    try {
      await apiRequest(`/admins/${deleteAdmin.dataset.deleteAdmin}`, { method: 'DELETE' });
      await loadAdmins();
      showToast('Akun berhasil dihapus.');
    } catch (error) { showToast(error.message); }
  }

  const taskButton = event.target.closest('[data-view-task]');
  if (taskButton) openSubmissionReview(taskButton.dataset.viewTask);
});

$('#closeReviewModal').addEventListener('click', closeSubmissionReview);
$('#openPdfNewTab').addEventListener('click', () => {
  if (activePdfUrl) window.open(activePdfUrl, '_blank', 'noopener');
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && !$('#reviewModal').classList.contains('hidden')) closeSubmissionReview();
});

$('#gradeForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!activeSubmission) return;
  const submission = activeSubmission;
  const score = Number($('#gradeScore').value);
  const errorMessage = $('#gradeFormError');
  errorMessage.classList.add('hidden');
  $('#saveGradeButton').disabled = true;
  $('#saveGradeSpinner').classList.remove('hidden');
  $('#saveGradeLabel').textContent = 'Menyimpan...';
  try {
    const result = await apiRequest(`/submissions/${submission.id}/grade`, {
      method: 'PUT',
      body: JSON.stringify({ score })
    });
    submission.grade = result.data;
    data.submissions.forEach((item) => {
      const sameGradeCell = item.nim === submission.nim
        && String(item.class.id) === String(submission.class.id)
        && String(item.course.id) === String(submission.course.id)
        && Number(item.meeting) === Number(submission.meeting);
      if (sameGradeCell) item.grade = String(item.id) === String(submission.id) ? result.data : null;
    });
    renderSubmissions();
    await loadGrades();
    if (activeSubmission?.id === submission.id) $('#saveGradeLabel').textContent = 'Perbarui nilai';
    showToast(result.message);
  } catch (error) {
    errorMessage.textContent = error.message;
    errorMessage.classList.remove('hidden');
    $('#saveGradeLabel').textContent = submission.grade ? 'Perbarui nilai' : 'Simpan nilai';
  } finally {
    $('#saveGradeButton').disabled = false;
    $('#saveGradeSpinner').classList.add('hidden');
  }
});

$('#dataForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  const name = $('#dataName').value.trim().toUpperCase();
  const resource = editing.type === 'class' ? 'classes' : 'courses';
  setCrudLoading(true, editing.id ? 'Memperbarui data...' : 'Menyimpan data...');
  try {
    await apiRequest(`/${resource}${editing.id ? `/${editing.id}` : ''}`, {
      method: editing.id ? 'PATCH' : 'POST',
      body: JSON.stringify({ name })
    });
    await loadReferenceData();
    closeDataModal();
    showToast('Data berhasil disimpan.');
  } catch (error) {
    $('#dataFormError').textContent = error.message;
    $('#dataFormError').classList.remove('hidden');
  } finally {
    setCrudLoading(false);
  }
});

$('#closeDataModal').addEventListener('click', closeDataModal);
$('#dataModal').addEventListener('click', (event) => { if (event.target === $('#dataModal')) closeDataModal(); });
$('#dataName').addEventListener('input', (event) => { event.target.value = event.target.value.toUpperCase(); });

$('#adminForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  const message = $('#adminFormMessage');
  try {
    await apiRequest('/admins', {
      method: 'POST',
      body: JSON.stringify({ email: $('#adminEmail').value.trim(), password: $('#adminPassword').value })
    });
    event.target.reset();
    await loadAdmins();
    message.textContent = 'Akun anggota berhasil ditambahkan.';
    message.className = 'rounded-xl bg-emerald-50 px-3 py-2.5 text-xs font-semibold text-emerald-600';
  } catch (error) {
    message.textContent = error.message;
    message.className = 'rounded-xl bg-red-50 px-3 py-2.5 text-xs font-semibold text-red-600';
  }
});

async function initialize() {
  sessionStorage.removeItem('kumpultugas-auth');
  if (!sessionStorage.getItem(AUTH_TOKEN_KEY)) return showLogin();
  try {
    const result = await apiRequest('/auth/me');
    currentUser = result.data;
    showApp();
    await loadAppData();
    showPage('submissions');
  } catch {
    sessionStorage.removeItem(AUTH_TOKEN_KEY);
    showLogin();
  }
}

initialize();
