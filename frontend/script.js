const form = document.querySelector('#submissionForm');
const nimInput = document.querySelector('#nim');
const nameInput = document.querySelector('#nama');
const classSelect = document.querySelector('#kelas');
const courseSelect = document.querySelector('#mataKuliah');
const fileInput = document.querySelector('#file');
const dropZone = document.querySelector('#dropZone');
const filePrompt = document.querySelector('#filePrompt');
const fileName = document.querySelector('#fileName');
const fileError = document.querySelector('#fileError');
const modal = document.querySelector('#successModal');
const closeModalButton = document.querySelector('#closeModal');
const submittedName = document.querySelector('#submittedName');
const submittedFile = document.querySelector('#submittedFile');
const formError = document.querySelector('#formError');
const submitButton = document.querySelector('#submitButton');
const submitButtonText = document.querySelector('#submitButtonText');

const API_CANDIDATES = [
  window.KUMPULTUGAS_API_URL,
  // 'http://127.0.0.1:8001/api/v1'
  'https://api.asisten.teraspelajar.com/api/v1'
].filter(Boolean);
let API_BASE_URL = API_CANDIDATES[0];
const maxFileSize = 10 * 1024 * 1024;
const allowedExtensions = ['pdf'];

function validateFile(file) {
  if (!file) return 'Silakan pilih berkas tugas terlebih dahulu.';

  const extension = file.name.split('.').pop().toLowerCase();
  if (!allowedExtensions.includes(extension)) return 'Format berkas harus PDF.';
  if (file.size > maxFileSize) return 'Ukuran berkas terlalu besar. Maksimal 10 MB.';
  return '';
}

function showFormError(message) {
  formError.textContent = message;
  formError.classList.toggle('hidden', !message);
}

function setSubmitting(isSubmitting) {
  submitButton.disabled = isSubmitting;
  submitButtonText.textContent = isSubmitting ? 'Mengirim tugas...' : 'Kirim tugas sekarang';
}

async function loadFormOptions() {
  try {
    await discoverApiBaseUrl();
    const [classResponse, courseResponse] = await Promise.all([
      fetch(`${API_BASE_URL}/classes`),
      fetch(`${API_BASE_URL}/courses`)
    ]);
    if (!classResponse.ok || !courseResponse.ok) throw new Error('Data form gagal dimuat.');
    const [classResult, courseResult] = await Promise.all([classResponse.json(), courseResponse.json()]);
    classSelect.innerHTML = '<option value="" disabled selected>Pilih kelas kamu</option>' + classResult.data.map((item) => `<option value="${item.id}">${item.code} — ${item.name}</option>`).join('');
    courseSelect.innerHTML = '<option value="" disabled selected>Pilih mata kuliah</option>' + courseResult.data.map((item) => `<option value="${item.id}">${item.code} — ${item.name}</option>`).join('');
  } catch {
    classSelect.innerHTML = '<option value="" disabled selected>Kelas gagal dimuat</option>';
    courseSelect.innerHTML = '<option value="" disabled selected>Mata kuliah gagal dimuat</option>';
    showFormError('Tidak dapat terhubung ke server. Pastikan backend Laravel sedang berjalan.');
  }
}

async function discoverApiBaseUrl() {
  for (const candidate of API_CANDIDATES) {
    try {
      const response = await fetch(`${candidate}/classes`, { headers: { Accept: 'application/json' } });
      if (!response.ok) continue;
      const result = await response.json();
      if (result.success === true && Array.isArray(result.data)) {
        API_BASE_URL = candidate;
        return;
      }
    } catch {
      // Coba kandidat port berikutnya.
    }
  }
  throw new Error('API KumpulTugas tidak ditemukan.');
}

nimInput.addEventListener('input', () => {
  nimInput.value = nimInput.value.toUpperCase();
});

nameInput.addEventListener('input', () => {
  nameInput.value = nameInput.value.toUpperCase();
});

function showFileError(message) {
  fileError.textContent = message;
  fileError.classList.toggle('hidden', !message);
  dropZone.classList.toggle('border-red-300', Boolean(message));
  dropZone.classList.toggle('bg-red-50', Boolean(message));
}

function updateFileLabel(file) {
  if (!file) {
    filePrompt.textContent = 'Klik untuk pilih berkas';
    fileName.textContent = 'atau seret dan lepas di sini';
    return;
  }

  filePrompt.textContent = 'Berkas siap diunggah';
  fileName.textContent = `${file.name} · ${(file.size / (1024 * 1024)).toFixed(2)} MB`;
}

function handleFile(file) {
  const error = validateFile(file);
  showFileError(error);
  if (error) {
    fileInput.value = '';
    updateFileLabel(null);
    return false;
  }
  updateFileLabel(file);
  return true;
}

fileInput.addEventListener('change', () => {
  handleFile(fileInput.files[0]);
});

['dragenter', 'dragover'].forEach((eventName) => {
  dropZone.addEventListener(eventName, (event) => {
    event.preventDefault();
    dropZone.classList.add('border-navy', 'bg-blue-50');
  });
});

['dragleave', 'drop'].forEach((eventName) => {
  dropZone.addEventListener(eventName, (event) => {
    event.preventDefault();
    dropZone.classList.remove('border-navy', 'bg-blue-50');
  });
});

dropZone.addEventListener('drop', (event) => {
  const [file] = event.dataTransfer.files;
  if (!file) return;

  const transfer = new DataTransfer();
  transfer.items.add(file);
  fileInput.files = transfer.files;
  handleFile(file);
});

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  showFormError('');
  const inputs = [...form.querySelectorAll('input[required], select[required]')];
  let isValid = true;

  inputs.forEach((input) => {
    const empty = !input.value.trim();
    input.classList.toggle('input-error', empty);
    if (empty) isValid = false;
  });

  if (!handleFile(fileInput.files[0])) isValid = false;
  if (!isValid) {
    const firstInvalid = form.querySelector('.input-error');
    firstInvalid?.focus();
    return;
  }

  const studentName = document.querySelector('#nama').value.trim();
  const selectedFileName = fileInput.files[0].name;
  const payload = new FormData();
  payload.append('nim', nimInput.value.trim().toUpperCase());
  payload.append('student_name', studentName);
  payload.append('class_id', classSelect.value);
  payload.append('course_id', courseSelect.value);
  payload.append('meeting', document.querySelector('#pertemuan').value);
  payload.append('file', fileInput.files[0]);

  setSubmitting(true);
  try {
    const response = await fetch(`${API_BASE_URL}/submissions`, { method: 'POST', body: payload });
    const result = await response.json();
    if (!response.ok) {
      const firstValidationError = result.errors ? Object.values(result.errors)[0]?.[0] : null;
      throw new Error(firstValidationError || result.message || 'Tugas gagal dikirim.');
    }

    submittedName.textContent = studentName;
    submittedFile.textContent = selectedFileName;
    form.reset();
    updateFileLabel(null);
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    closeModalButton.focus();
  } catch (error) {
    showFormError(error.message || 'Tidak dapat terhubung ke server.');
  } finally {
    setSubmitting(false);
  }
});

function closeModal() {
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}

closeModalButton.addEventListener('click', closeModal);
modal.addEventListener('click', (event) => {
  if (event.target === modal) closeModal();
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
});

loadFormOptions();
