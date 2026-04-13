document.addEventListener('DOMContentLoaded', () => {
    // 1. Elementi DOM
    const corsoSelect = document.getElementById('corso');
    const docenteDisplay = document.getElementById('docenteDisplay');
    const budgetDisplay = document.getElementById('budgetDisplay');
    const inputDocente = document.getElementById('docente');
    const inputBudgetIniziale = document.getElementById('budget_iniziale');
    const infoCard = document.getElementById('infoCard');
    
    const activitiesGrid = document.getElementById('activitiesGrid');
    const totalValueDisplay = document.getElementById('totalValue');
    const inputCostoTotale = document.getElementById('costo_totale');
    
    const budgetAlert = document.getElementById('budgetAlert');
    const submitBtn = document.getElementById('submitBtn');
    const form = document.getElementById('budgetForm');
    
    const successMsg = document.getElementById('success-msg');
    const btnText = document.getElementById('btnText');
    const btnSpinner = document.getElementById('btnSpinner');

    let currentBudget = 0;

    // 2. Mappa Attività e Nomi Input per coerenza backend
    const mapAttivitaInputName = {
        'Supporto contratti': 'ore_contratti',
        'Supporto studenti': 'ore_studenti',
        'Conferenze': 'ore_conferenze',
        'Tutorato studenti': 'ore_tut_studenti',
        'Tutorato dottorandi': 'ore_tut_dottorandi'
    };

    // 3. Popola Select Insegnamenti
    // corsiData è iniettata da PHP in index.php
    corsiData.forEach((corso, index) => {
        const option = document.createElement('option');
        option.value = index; // Uso l'indice per recuperare l'oggetto
        option.textContent = corso.corso;
        corsoSelect.appendChild(option);
    });

    const activitiesTableBody = document.getElementById('activitiesTableBody');

    // 4. Genera Griglia Input Attività dinamicamente
    Object.keys(tariffeData).forEach(attivitaName => {
        const tariffa = parseFloat(tariffeData[attivitaName]) || 0;
        const inputName = mapAttivitaInputName[attivitaName] || attivitaName.replace(/\s+/g, '_').toLowerCase();
        
        const row = document.createElement('tr');
        row.innerHTML = `
            <td class="activity-name">${attivitaName}</td>
            <td>
                <input type="checkbox" name="${inputName}_attiva" class="attiva-checkbox">
            </td>
            <td>
                <input type="number" name="${inputName}_studenti" min="0" step="1" placeholder="N." class="studenti-input" disabled>
            </td>
            <td>
                <input type="number" name="${inputName}" data-tariffa="${tariffa}" min="0" step="1" placeholder="Ore" class="hour-input" disabled>
            </td>
            <td>
                <input type="checkbox" name="${inputName}_colloquio" class="colloquio-checkbox" disabled>
            </td>
            <td class="row-cost-col" id="cost_${inputName}">€ 0.00</td>
        `;
        activitiesTableBody.appendChild(row);
        
        // Logica per abilitare/disabilitare riga
        const checkboxAttiva = row.querySelector('.attiva-checkbox');
        const inputStudenti = row.querySelector('.studenti-input');
        const inputOre = row.querySelector('.hour-input');
        const checkboxColloquio = row.querySelector('.colloquio-checkbox');
        
        checkboxAttiva.addEventListener('change', function() {
            const isActive = this.checked;
            inputStudenti.disabled = !isActive;
            inputOre.disabled = !isActive;
            checkboxColloquio.disabled = !isActive;
            if (!isActive) {
                inputStudenti.value = '';
                inputOre.value = '';
                checkboxColloquio.checked = false;
            }
            calculateTotal();
        });
    });

    const hourInputs = document.querySelectorAll('.hour-input');



    // 5. Gestione Cambio Corso
    corsoSelect.addEventListener('change', function() {
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

        // Controllo se è già stata compilata questa materia
        if (typeof compilazioniPrecedentiData !== 'undefined' && compilazioniPrecedentiData[selectedCorso.corso]) {
            const oldValue = compilazioniPrecedentiData[selectedCorso.corso];
            // Ripristino valori form principale (Privacy: NON ripristiniamo Nome e Cognome)
            // Ripristiniamo solo le motivazioni e le ore
            document.getElementById('motivazioni').value = oldValue.motivazioni || '';
            
            // Ripristino valori per ogni input orario
            // Prima iteriamo su tutte le righe generiche
            Object.keys(tariffeData).forEach(attivitaName => {
                const inputName = mapAttivitaInputName[attivitaName] || attivitaName.replace(/\s+/g, '_').toLowerCase();
                if (oldValue[inputName] !== undefined && oldValue[inputName] !== null) {
                    const ore = oldValue[inputName] || 0;
                    const checkAttiva = document.querySelector(`input[name="${inputName}_attiva"]`);
                    const checkColloquio = document.querySelector(`input[name="${inputName}_colloquio"]`);
                    const inputStudenti = document.querySelector(`input[name="${inputName}_studenti"]`);
                    const inputOreForm = document.querySelector(`input[name="${inputName}"]`);
                    // Logic per dedurre lo state
                    if (ore > 0 || (oldValue[`${inputName}_studenti`] !== undefined && oldValue[`${inputName}_studenti`] > 0)) {
                        checkAttiva.checked = true;
                        inputOreForm.disabled = false;
                        inputStudenti.disabled = false;
                        checkColloquio.disabled = false;
                        inputOreForm.value = ore;
                        inputStudenti.value = oldValue[`${inputName}_studenti`] || '';
                        checkColloquio.checked = oldValue[`${inputName}_colloquio`] === "1" || oldValue[`${inputName}_colloquio`] === true;
                    } else {
                        checkAttiva.checked = false;
                        inputOreForm.disabled = true;
                        inputStudenti.disabled = true;
                        checkColloquio.disabled = true;
                    }
                }
            });
        } else {
            // Svuotamento se nuovo
            document.getElementById('motivazioni').value = '';
            document.querySelectorAll('.attiva-checkbox').forEach(chk => chk.checked = false);
            document.querySelectorAll('.studenti-input, .hour-input, .colloquio-checkbox').forEach(inp => {
                inp.disabled = true;
                if(inp.type === 'checkbox') inp.checked = false;
                else inp.value = '';
            });
        }
        
        // Ricalcola in caso vi siano già ore inserite
        calculateTotal();
    });

    // 6. Motore di Calcolo (Event Listeners su input num)
    const allInputsToWatch = document.querySelectorAll('.hour-input, .studenti-input');
    allInputsToWatch.forEach(input => {
        input.addEventListener('input', calculateTotal);
    });

    function calculateTotal() {
        let totalCost = 0;
        
        hourInputs.forEach(input => {
            const row = input.closest('tr');
            const isActive = row.querySelector('.attiva-checkbox').checked;
            const inputName = input.getAttribute('name');
            const tariffa = parseFloat(input.getAttribute('data-tariffa')) || 0;
            const rowCostCol = document.getElementById(`cost_${inputName}`);
            
            let rowCost = 0;
            if (isActive) {
                const ore = parseFloat(input.value) || 0;
                // Estrae input degli Studenti corretto per questa riga
                const inputStudenti = row.querySelector('.studenti-input');
                let studenti = parseInt(inputStudenti.value) || 1; 
                if (studenti <= 0) studenti = 1; // Previene azzeramento se lasciato vuoto o zero errato
                
                rowCost = ore * tariffa * studenti;
                totalCost += rowCost;
            }
            // Aggiorna Costo singola riga
            rowCostCol.textContent = formatCurrency(rowCost);
        });

        // Aggiorna Visualizzazione
        totalValueDisplay.textContent = formatCurrency(totalCost);
        inputCostoTotale.value = totalCost;

        // 7. Validatore Budget CRUCIALE
        validateBudget(totalCost);
    }

    function validateBudget(totalCost) {
        // Se non c'è corso selezionato, non abilitiamo ma non mostriamo l'alert
        if (!corsoSelect.value) {
            submitBtn.disabled = true;
            return;
        }

        if (totalCost > currentBudget) {
            totalValueDisplay.classList.remove('budget-ok');
            totalValueDisplay.classList.add('budget-error');
            budgetAlert.classList.add('visible');
            submitBtn.disabled = true;
        } else {
            totalValueDisplay.classList.remove('budget-error');
            totalValueDisplay.classList.add('budget-ok');
            budgetAlert.classList.remove('visible');
            
            // Abilita solo se c'è almeno 1 centesimo speso, oppure se vogliamo permettere sottomissioni a zero?
            // La logica standard vorrebbe che sia inviabile, ma diciamo che basta che ci sia un corso valido e budget non sforato.
            // Controlliamo che corsoSelect abbia un valore valido:
            submitBtn.disabled = false;
        }
    }

    function formatCurrency(amount) {
        return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(amount);
    }

    // Custom Modal Elements
    const customModal = document.getElementById('customConfirmModal');
    const btnCancelModal = document.getElementById('btnCancelModal');
    const btnConfirmModal = document.getElementById('btnConfirmModal');
    
    // Funzione interna per inviare fisicamente i dati
    async function performSubmission(formData) {
        // UI Feedback: Loading
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
                // Redirect alla pagina di ringraziamento
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

    // 8. Gestione Submit Form e AJAX
    form.addEventListener('submit', (e) => {
        e.preventDefault();

        // Validazione extra di sicurezza
        if (submitBtn.disabled) return;

        const formData = new FormData(form);
        const selectedCorsoObj = corsiData[corsoSelect.value];
        formData.set('corso', selectedCorsoObj.corso);

        // Controllo Sovrascrittura e mostra Modal Custom
        if (typeof compilazioniPrecedentiData !== 'undefined' && compilazioniPrecedentiData[selectedCorsoObj.corso]) {
            // Mostra modale custom anziché bloccare
            customModal.classList.add('active');
            
            // Gestione Click "Annulla"
            btnCancelModal.onclick = () => {
                customModal.classList.remove('active');
            };
            
            // Gestione Click "Conferma"
            btnConfirmModal.onclick = () => {
                customModal.classList.remove('active');
                performSubmission(formData);
            };
        } else {
            // Se non c'è conflitto prosieguo dritto
            performSubmission(formData);
        }
    });
});
