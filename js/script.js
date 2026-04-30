document.addEventListener('DOMContentLoaded', () => {
    // 1. Elementi DOM
    const corsoSelect = document.getElementById('corso');
    const docenteDisplay = document.getElementById('docenteDisplay');
    const budgetDisplay = document.getElementById('budgetDisplay');
    const inputDocente = document.getElementById('docente');
    const inputBudgetIniziale = document.getElementById('budget_iniziale');
    const infoCard = document.getElementById('infoCard');

    const activitiesGridNoPrice = document.getElementById('activitiesTableBodyNoPrice');
    const activitiesGridPrice = document.getElementById('activitiesTableBodyPrice');
    const totalValueDisplay = document.getElementById('totalValue');
    const inputCostoTotale = document.getElementById('costo_totale');

    const budgetAlert = document.getElementById('budgetAlert');
    const submitBtn = document.getElementById('submitBtn');
    const form = document.getElementById('budgetForm');

    const successMsg = document.getElementById('success-msg');
    const btnText = document.getElementById('btnText');
    const btnSpinner = document.getElementById('btnSpinner');

    const inputNome = document.getElementById('nome');
    const inputCognome = document.getElementById('cognome');
    const inputCompetenze = document.getElementById('competenze');

    let currentBudget = 0;

    // 2. Chiavi Dinamiche per Dettagli JSON
    let keysNoPrice = ['costo'];
    let keysPrice = ['studenti', 'ore'];

    document.querySelectorAll('#activitiesGridNoPrice th').forEach(th => {
        if (th.textContent.includes('Dettagli')) {
            const match = th.textContent.match(/\((.*?)\)/);
            if (match) keysNoPrice = match[1].split(/[,/]/).map(s => s.trim().toLowerCase());
        }
    });

    document.querySelectorAll('#activitiesGridPrice th').forEach(th => {
        if (th.textContent.includes('Dettagli')) {
            const match = th.textContent.match(/\((.*?)\)/);
            if (match) keysPrice = match[1].split(/[,/]/).map(s => s.trim().toLowerCase());
        }
    });

    // 3. Popola Select Insegnamenti
    corsiData.forEach((corso, index) => {
        const option = document.createElement('option');
        option.value = index;
        option.textContent = corso.corso;
        corsoSelect.appendChild(option);
    });

    // 3. Genera Griglie Input Attività dinamicamente
    // tariffeData è ora un oggetto { "Nome": { costo: 32, con_spesa: true }, ... }
    Object.keys(tariffeData).forEach(attivitaName => {
        const datiAttivita = tariffeData[attivitaName];
        const isPrice = datiAttivita.con_spesa;
        const tariffa = parseFloat(datiAttivita.costo) || 0;
        const inputName = attivitaName.replace(/\s+/g, '_').toLowerCase();

        const row = document.createElement('tr');
        row.setAttribute('data-attivita', inputName);
        row.setAttribute('data-is-price', isPrice ? '1' : '0');
        row.setAttribute('data-tariffa', tariffa);

        let infoCosto = isPrice ? `<br><small>(${tariffa}€/h)</small>` : '';

        let colloquioCol = isPrice ? `
            <td>
                <input type="checkbox" name="${inputName}_colloquio" class="colloquio-checkbox" disabled>
            </td>` : '';

        row.innerHTML = `
            <td class="activity-name">${attivitaName}${infoCosto}</td>
            <td>
                <input type="checkbox" name="${inputName}_attiva" class="attiva-checkbox">
            </td>
            ${colloquioCol}
            <td>
                <div class="subvoci-container" id="container_${inputName}"></div>
                <button type="button" class="btn-add-subvoce" id="btnAdd_${inputName}" disabled style="margin-top:5px; font-size: 0.8rem; padding: 4px 8px;">+ Aggiungi</button>
                <input type="hidden" name="${inputName}_dettagli" id="dettagli_${inputName}" value="[]">
            </td>
            <td class="row-cost-col" id="cost_${inputName}" style="font-weight: bold;">€ 0.00</td>
        `;

        if (isPrice) {
            activitiesGridPrice.appendChild(row);
        } else {
            activitiesGridNoPrice.appendChild(row);
        }

        // Logica per abilitare/disabilitare riga
        const checkboxAttiva = row.querySelector('.attiva-checkbox');
        const checkboxColloquio = row.querySelector('.colloquio-checkbox');
        const btnAdd = row.querySelector('.btn-add-subvoce');
        const container = row.querySelector('.subvoci-container');

        checkboxAttiva.addEventListener('change', function () {
            const isActive = this.checked;
            if (checkboxColloquio) checkboxColloquio.disabled = !isActive;
            btnAdd.disabled = !isActive;
            
            if (!isActive) {
                if (checkboxColloquio) checkboxColloquio.checked = false;
                container.innerHTML = ''; // Rimuovi tutte le sottovoci
            } else if (container.children.length === 0) {
                // Aggiungi subito una prima sottovoce vuota
                addSubvoce(inputName, isPrice, container);
            }
            calculateTotal();
        });

        btnAdd.addEventListener('click', () => {
            addSubvoce(inputName, isPrice, container);
            calculateTotal();
        });
    });

    function addSubvoce(inputName, isPrice, container, initStudenti = '', initValore = '') {
        const subRow = document.createElement('div');
        subRow.className = 'subvoce-row';
        subRow.style.display = 'flex';
        subRow.style.gap = '5px';
        subRow.style.marginBottom = '5px';

        const placeholderValore = isPrice ? 'Ore' : 'Costo Fisso €';

        let studentiInput = isPrice ? `
            <input type="number" class="sv-studenti" placeholder="N. Pers." min="1" step="1" value="${initStudenti}" required style="width: 40%; padding: 4px;">
        ` : '';
        let widthValore = isPrice ? '40%' : '80%';

        subRow.innerHTML = `
            ${studentiInput}
            <input type="number" class="sv-valore" placeholder="${placeholderValore}" min="0" step="0.5" value="${initValore}" required style="width: ${widthValore}; padding: 4px;">
            <button type="button" class="btn-remove-subvoce" style="width: 15%; color: red; cursor: pointer; padding: 4px;">X</button>
        `;

        container.appendChild(subRow);

        const btnRemove = subRow.querySelector('.btn-remove-subvoce');
        btnRemove.addEventListener('click', () => {
            subRow.remove();
            calculateTotal();
        });

        const inputs = subRow.querySelectorAll('input');
        inputs.forEach(inp => inp.addEventListener('input', calculateTotal));
    }


    // 4. Gestione Cambio Corso
    corsoSelect.addEventListener('change', function () {
        if (this.value === "") {
            infoCard.classList.remove('visible');
            return;
        }

        const selectedCorso = corsiData[this.value];

        docenteDisplay.textContent = selectedCorso.docente;
        inputDocente.value = selectedCorso.docente;

        currentBudget = parseFloat(selectedCorso.budget) || 0;
        budgetDisplay.textContent = formatCurrency(currentBudget);
        inputBudgetIniziale.value = currentBudget;

        infoCard.classList.add('visible');

        // Svuotamento preventivo
        document.getElementById('competenze').value = '';
        document.getElementById('note').value = '';
        document.querySelectorAll('.attiva-checkbox, .colloquio-checkbox').forEach(chk => {
            chk.checked = false;
            if(chk.classList.contains('colloquio-checkbox')) chk.disabled = true;
        });
        document.querySelectorAll('.btn-add-subvoce').forEach(btn => btn.disabled = true);
        document.querySelectorAll('.subvoci-container').forEach(c => c.innerHTML = '');

        // Controllo se è già stata compilata questa materia
        if (typeof compilazioniPrecedentiData !== 'undefined' && compilazioniPrecedentiData[selectedCorso.corso]) {
            const oldValue = compilazioniPrecedentiData[selectedCorso.corso];
            
            document.getElementById('competenze').value = oldValue.competenze || '';
            document.getElementById('note').value = oldValue.note || '';

            Object.keys(tariffeData).forEach(attivitaName => {
                const inputName = attivitaName.replace(/\s+/g, '_').toLowerCase();
                const isPrice = tariffeData[attivitaName].con_spesa;
                
                if (oldValue[`${inputName}_attiva`]) {
                    const checkAttiva = document.querySelector(`input[name="${inputName}_attiva"]`);
                    const checkColloquio = document.querySelector(`input[name="${inputName}_colloquio"]`);
                    const container = document.getElementById(`container_${inputName}`);
                    const btnAdd = document.getElementById(`btnAdd_${inputName}`);
                    
                    if(checkAttiva) {
                        checkAttiva.checked = true;
                        if(checkColloquio) {
                            checkColloquio.disabled = false;
                            checkColloquio.checked = oldValue[`${inputName}_colloquio`] === true;
                        }
                        if(btnAdd) btnAdd.disabled = false;
                        
                        const sottovoci = oldValue[`${inputName}_sottovoci`];
                        if (sottovoci && Array.isArray(sottovoci)) {
                            sottovoci.forEach(sv => {
                                let studVal = '';
                                let oreVal = '';
                                const vals = Object.values(sv);
                                if (isPrice) {
                                    studVal = sv[keysPrice[0]] !== undefined ? sv[keysPrice[0]] : (sv['studenti'] !== undefined ? sv['studenti'] : (vals[0] || ''));
                                    oreVal = sv[keysPrice[1]] !== undefined ? sv[keysPrice[1]] : (sv['ore'] !== undefined ? sv['ore'] : (vals[1] || ''));
                                } else {
                                    oreVal = sv[keysNoPrice[0]] !== undefined ? sv[keysNoPrice[0]] : (sv['costo'] !== undefined ? sv['costo'] : (vals[0] || ''));
                                }
                                addSubvoce(inputName, isPrice, container, studVal, oreVal);
                            });
                        }
                    }
                }
            });
        }

        calculateTotal();
    });

    // Validazione globale extra
    ['input', 'change', 'keyup'].forEach(evt => {
        form.addEventListener(evt, (e) => {
            if(!e.target.classList.contains('sv-studenti') && !e.target.classList.contains('sv-valore')) {
               calculateTotal();
            }
        });
    });

    function calculateTotal() {
        let totalCostAll = 0; // Tutto influenza il budget
        let hasActiveRows = false;
        let allActiveFilled = true;

        const allRows = document.querySelectorAll('tr[data-attivita]');
        
        allRows.forEach(row => {
            const isActive = row.querySelector('.attiva-checkbox').checked;
            const isPrice = row.getAttribute('data-is-price') === '1';
            const inputName = row.getAttribute('data-attivita');
            const tariffa = parseFloat(row.getAttribute('data-tariffa')) || 0;
            const rowCostCol = document.getElementById(`cost_${inputName}`);
            const inputDettagli = document.getElementById(`dettagli_${inputName}`);
            const container = document.getElementById(`container_${inputName}`);

            let rowSum = 0;
            let dettagliArr = [];

            if (isActive) {
                hasActiveRows = true;
                const subRows = container.querySelectorAll('.subvoce-row');
                
                if (subRows.length === 0) {
                    allActiveFilled = false;
                }

                subRows.forEach(sub => {
                    const inputVal = sub.querySelector('.sv-valore');
                    const valStr = inputVal.value.trim();
                    const val = parseFloat(valStr) || 0;

                    if (isPrice) {
                        const inputStud = sub.querySelector('.sv-studenti');
                        const studStr = inputStud.value.trim();
                        const stud = parseInt(studStr) || 0;

                        if (studStr === '' || stud <= 0 || valStr === '' || val <= 0) {
                            allActiveFilled = false;
                        } else {
                            let rowSubCost = stud * val * tariffa;
                            let detailObj = {};
                            detailObj[keysPrice[0] || 'studenti'] = stud;
                            detailObj[keysPrice[1] || 'ore'] = val;
                            dettagliArr.push(detailObj);
                            rowSum += rowSubCost;
                        }
                    } else {
                        if (valStr === '' || val <= 0) {
                            allActiveFilled = false;
                        } else {
                            let rowSubCost = val; // Qui il valore inserito è già il costo fisso
                            let detailObj = {};
                            detailObj[keysNoPrice[0] || 'costo'] = val;
                            dettagliArr.push(detailObj);
                            rowSum += rowSubCost;
                        }
                    }
                });

                totalCostAll += rowSum;
            }
            
            // Aggiorna Costo singola riga
            rowCostCol.textContent = formatCurrency(rowSum);
            // Aggiorna hidden input con JSON per il backend
            inputDettagli.value = JSON.stringify(dettagliArr);
        });

        const isActivityValid = hasActiveRows && allActiveFilled;

        // Aggiorna Visualizzazione Totale
        totalValueDisplay.textContent = formatCurrency(totalCostAll);
        inputCostoTotale.value = totalCostAll;

        // Validatore Budget CRUCIALE
        validateBudget(totalCostAll, isActivityValid);
    }

    function validateBudget(totalCost, isActivityValid) {
        if (!corsoSelect.value) {
            submitBtn.disabled = true;
            return;
        }

        const isTextValid = inputNome.value.trim() !== '' &&
            inputCognome.value.trim() !== '' &&
            inputCompetenze.value.trim() !== '';

        const isFormValid = form.checkValidity();

        if (totalCost > currentBudget) {
            totalValueDisplay.classList.remove('budget-ok');
            totalValueDisplay.classList.add('budget-error');
            budgetAlert.classList.add('visible');
            submitBtn.disabled = true;
        } else {
            totalValueDisplay.classList.remove('budget-error');
            totalValueDisplay.classList.add('budget-ok');
            budgetAlert.classList.remove('visible');

            if (isTextValid && isFormValid && isActivityValid) {
                submitBtn.disabled = false;
            } else {
                submitBtn.disabled = true;
            }
        }
    }

    function formatCurrency(amount) {
        return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(amount);
    }

    // Modal
    const customModal = document.getElementById('customConfirmModal');
    const btnCancelModal = document.getElementById('btnCancelModal');
    const btnConfirmModal = document.getElementById('btnConfirmModal');

    async function performSubmission(formData) {
        submitBtn.disabled = true;
        btnText.style.display = 'none';
        btnSpinner.style.display = 'block';
        successMsg.style.display = 'none';

        try {
            const response = await fetch('api/process.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                successMsg.textContent = result.message;
                successMsg.style.display = 'block';
                window.location.href = 'success.html';
            } else {
                alert("Errore: " + result.message);
                resetSubmitBtn();
            }
        } catch (error) {
            console.error("Errore fetch:", error);
            alert("Si è verificato un errore di rete o di sistema.");
            resetSubmitBtn();
        }
    }

    function resetSubmitBtn() {
        submitBtn.disabled = false;
        btnText.style.display = 'inline';
        btnSpinner.style.display = 'none';
    }

    form.addEventListener('submit', (e) => {
        e.preventDefault();

        if (submitBtn.disabled) return;

        const formData = new FormData(form);
        const selectedCorsoObj = corsiData[corsoSelect.value];
        formData.set('corso', selectedCorsoObj.corso);

        if (typeof compilazioniPrecedentiData !== 'undefined' && compilazioniPrecedentiData[selectedCorsoObj.corso]) {
            customModal.classList.add('active');

            btnCancelModal.onclick = () => {
                customModal.classList.remove('active');
            };

            btnConfirmModal.onclick = () => {
                customModal.classList.remove('active');
                performSubmission(formData);
            };
        } else {
            performSubmission(formData);
        }
    });

    form.reset();
    submitBtn.disabled = true;
});
