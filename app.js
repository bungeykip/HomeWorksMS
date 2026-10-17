files["assets/app.js"] = r'''function updateClock() {
const now = new Date();
const clock = document.getElementById('digitalClock');
const date = document.getElementById('currentDate');
if (clock) clock.textContent = now.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit',
hour12: false});
if (date) date.textContent = now.toLocaleDateString([], {weekday: 'short', day: '2-digit', month: 'short', year:
'numeric'});
updateClock();
setInterval(updateClock, 1000);

const modal = document.getElementById('workerModal');
const workerForm = document.getElementById('workerForm');
const categorySelect = document.getElementById('workerCategory');
const salaryField = document.getElementById('salaryField');

function syncSalaryField() {
if (!categorySelect || !salaryField) return;
const isTea = categorySelect.value === 'Tea Worker';
salaryField.style.display = isTea ? 'none' : 'block';
const salary = document.getElementById('workerSalary');
if (isTea && salary) salary.value = '0';
}
if (categorySelect) categorySelect.addEventListener('change', syncSalaryField);

function openWorkerModal() {
if (!modal || !workerForm) return;
workerForm.reset();
document.getElementById('workerAction').value = 'add_worker';
document.getElementById('editWorkerId').value = '';
document.getElementById('workerModalTitle').textContent = 'Add new worker';
document.getElementById('workerSubmit').textContent = 'Save worker';
document.getElementById('workerCode').disabled = false;
const selectedCategory = new URLSearchParams(window.location.search).get('category');
if (selectedCategory && ['Manager','Driver','Turn Worker','Home Worker','Tea Worker'].includes(selectedCategory)) {
categorySelect.value = selectedCategory;
}
syncSalaryField();
modal.classList.add('visible');
modal.setAttribute('aria-hidden', 'false');
document.getElementById('workerName').focus();
}
function closeWorkerModal() {
if (!modal) return;
modal.classList.remove('visible');
modal.setAttribute('aria-hidden', 'true');
}
function editWorker(worker) {
if (!modal || !workerForm) return;
workerForm.reset();
document.getElementById('workerAction').value = 'update_worker';
document.getElementById('editWorkerId').value = worker.id;
document.getElementById('workerModalTitle').textContent = 'Edit worker details';
document.getElementById('workerSubmit').textContent = 'Update worker';
document.getElementById('workerName').value = worker.name || '';
document.getElementById('workerPhone').value = worker.phone || '';
document.getElementById('workerSalary').value = worker.salary || 0;
document.getElementById('workerCode').value = worker.code || '';
document.getElementById('workerCode').disabled = true;
categorySelect.value = worker.category;
syncSalaryField();
modal.classList.add('visible');
modal.setAttribute('aria-hidden', 'false');
document.getElementById('workerName').focus();
}
if (modal) {
modal.addEventListener('click', (event) => { if (event.target === modal) closeWorkerModal(); });
document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeWorkerModal(); });
}
document.querySelectorAll('.kg-input').forEach(input => {
input.addEventListener('input', () => {
const row = input.closest('tr');
const values = [...row.querySelectorAll('.kg-input')].map(el => Math.max(0, Number(el.value || 0)));
const total = values.reduce((sum, value) => sum + value, 0);
const output = row.querySelector('.kg-total strong');
if (output) output.textContent = total.toFixed(2);
});
});
document.querySelectorAll('.row-payroll-form, .payroll-form').forEach(form => {
form.addEventListener('submit', (event) => {
const fields = form.querySelectorAll('input[type="number"]');
for (const field of fields) {
if (field.value === '' || Number(field.value) < 0) { event.preventDefault(); field.focus(); alert('Enter a valid amount
    of zero or more.'); return; } } }); });