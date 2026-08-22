import * as bootstrap from 'bootstrap';
import Swal from 'sweetalert2';
import DataTable from 'datatables.net-bs5';
import TomSelect from 'tom-select';
import 'datatables.net-responsive-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
import 'datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css';
import 'sweetalert2/dist/sweetalert2.min.css';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';

window.Swal = Swal;

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
const ajaxModalElement = document.getElementById('ajaxModal');
const ajaxModal = ajaxModalElement ? new bootstrap.Modal(ajaxModalElement) : null;
const ajaxModalTitle = document.getElementById('ajaxModalTitle');
const ajaxModalBody = ajaxModalElement?.querySelector('[data-modal-body]');
const ajaxModalDialog = ajaxModalElement?.querySelector('.modal-dialog');

const toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 2600,
    timerProgressBar: true,
});

function showInitialAlerts() {
    const success = document.querySelector('[data-swal-success]')?.dataset.swalSuccess;
    const warning = document.querySelector('[data-swal-warning]')?.dataset.swalWarning;
    const error = document.querySelector('[data-swal-error]')?.dataset.swalError;

    if (success) {
        toast.fire({ icon: 'success', title: success });
    }

    if (warning) {
        toast.fire({ icon: 'warning', title: warning });
    }

    if (error) {
        Swal.fire({ icon: 'error', title: 'Atencion', text: error });
    }
}

async function fetchHtml(url) {
    const response = await fetch(url, {
        headers: {
            Accept: 'text/html',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error('No se pudo cargar el contenido solicitado.');
    }

    return response.text();
}

function openAjaxModal(trigger) {
    if (!ajaxModal || !ajaxModalBody || !ajaxModalTitle) {
        window.location.href = trigger.href;

        return;
    }

    ajaxModalTitle.textContent = trigger.dataset.modalTitle ?? 'Detalle';
    ajaxModalDialog?.classList.remove('modal-sm', 'modal-lg', 'modal-xl');
    ajaxModalDialog?.classList.add(`modal-${trigger.dataset.modalSize ?? 'lg'}`);
    ajaxModalBody.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';
    ajaxModal.show();

    fetchHtml(trigger.dataset.modalUrl ?? trigger.href)
        .then((html) => {
            ajaxModalBody.innerHTML = html;
            initializeUi(ajaxModalBody);
        })
        .catch((error) => {
            ajaxModal.hide();
            Swal.fire({ icon: 'error', title: 'Error', text: error.message });
        });
}

function disableBusinessFormAutocomplete(scope = document) {
    scope.querySelectorAll('form[data-ajax-form], .form-panel form').forEach((form) => {
        form.setAttribute('autocomplete', 'off');
    });

    scope.querySelectorAll('form[data-ajax-form] input, form[data-ajax-form] textarea, .form-panel input, .form-panel textarea').forEach((field) => {
        if (['hidden', 'checkbox', 'radio', 'submit', 'button'].includes(field.type)) {
            return;
        }

        const shouldUsePasswordToken = field.name === 'name' || field.id.endsWith('-name');
        field.setAttribute('autocomplete', shouldUsePasswordToken ? 'new-password' : 'off');
        field.setAttribute('data-lpignore', 'true');
        field.setAttribute('data-1p-ignore', 'true');
    });
}

function clearFormErrors(form) {
    form.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
    form.querySelectorAll('[data-error-for]').forEach((target) => {
        target.textContent = '';
    });
}

function showFormErrors(form, errors) {
    Object.entries(errors).forEach(([field, messages]) => {
        const input = form.querySelector(`[name="${field}"]`);
        const feedback = form.querySelector(`[data-error-for="${field}"]`);

        input?.classList.add('is-invalid');

        if (feedback) {
            feedback.textContent = messages[0] ?? 'Dato invalido.';
        }
    });
}

function validationSummary(errors, fallback = 'Revisa los datos ingresados.') {
    const messages = Object.values(errors)
        .flat()
        .filter(Boolean);

    return messages.length > 0 ? messages.join('\n') : fallback;
}

function setSubmitting(form, submitting) {
    const submit = form.querySelector('[type="submit"]');
    const spinner = form.querySelector('[data-submit-spinner]');

    if (submit) {
        submit.disabled = submitting;
    }

    spinner?.classList.toggle('d-none', !submitting);
}

async function refreshContainer(url) {
    const current = document.querySelector('[data-refresh-container]');

    if (!current) {
        return;
    }

    const html = await fetchHtml(url ?? window.location.href);
    const documentFragment = new DOMParser().parseFromString(html, 'text/html');
    const fresh = documentFragment.querySelector('[data-refresh-container]');

    if (fresh) {
        current.replaceWith(fresh);
        initializeUi(fresh);
    }
}

async function submitAjaxForm(form) {
    clearFormErrors(form);
    setSubmitting(form, true);

    try {
        const response = await fetch(form.action, {
            method: form.method.toUpperCase(),
            body: new FormData(form),
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const payload = await response.json();

        if (response.status === 422) {
            const errors = payload.errors ?? payload.data ?? {};
            showFormErrors(form, errors);
            Swal.fire({ icon: 'error', title: 'Validacion', text: validationSummary(errors, payload.message) });

            return;
        }

        if (!response.ok || payload.success === false) {
            throw new Error(payload.message ?? 'No se pudo completar la operacion.');
        }

        document.dispatchEvent(new CustomEvent('ajax-form:success', {
            detail: {
                form,
                payload,
            },
        }));

        if (payload.redirect_url) {
            window.location.assign(payload.redirect_url);

            return;
        }

        ajaxModal?.hide();
        await refreshContainer(payload.refresh_url ?? form.dataset.refreshUrl);
        toast.fire({ icon: 'success', title: payload.message ?? 'Operacion realizada correctamente.' });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error', text: error.message });
    } finally {
        setSubmitting(form, false);
    }
}

function confirmDelete(form) {
    Swal.fire({
        icon: 'warning',
        title: form.dataset.confirmDelete ?? 'Confirmar eliminacion',
        text: 'Esta accion no se puede deshacer facilmente.',
        showCancelButton: true,
        confirmButtonText: 'Si, eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#dc3545',
    }).then((result) => {
        if (result.isConfirmed) {
            if (form.matches('[data-ajax-form]')) {
                submitAjaxForm(form);

                return;
            }

            form.submit();
        }
    });
}

function confirmAction(form) {
    Swal.fire({
        icon: 'warning',
        title: form.dataset.confirmTitle ?? '¿Confirmar esta acción?',
        text: form.dataset.confirmText ?? 'Revisa la información antes de continuar.',
        showCancelButton: true,
        confirmButtonText: form.dataset.confirmButton ?? 'Sí, continuar',
        cancelButtonText: 'Volver y revisar',
        confirmButtonColor: '#d63939',
        reverseButtons: true,
        focusCancel: true,
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
}

function initAdminDataTables(scope = document) {
    scope.querySelectorAll('[data-datatable]').forEach((table) => {
        if (table.dataset.datatableInitialized === '1') {
            return;
        }

        const columnsElement = document.getElementById(table.dataset.columnsId ?? '');
        const columns = JSON.parse(columnsElement?.textContent ?? table.dataset.columns ?? '[]');
        const filtersForm = table.dataset.filtersForm ? document.querySelector(table.dataset.filtersForm) : null;

        const dataTable = new DataTable(table, {
            ajax: {
                url: table.dataset.url,
                data(data) {
                    if (!filtersForm) {
                        return;
                    }

                    new FormData(filtersForm).forEach((value, key) => {
                        data[key] = value;
                    });
                },
            },
            columns,
            processing: true,
            serverSide: true,
            responsive: true,
            pageLength: Number(table.dataset.pageLength ?? 10),
            order: JSON.parse(table.dataset.order ?? '[[0,"desc"]]'),
            language: {
                search: 'Buscar:',
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
                infoEmpty: 'Sin registros',
                infoFiltered: '(filtrado de _MAX_ registros)',
                loadingRecords: 'Cargando...',
                processing: 'Procesando...',
                zeroRecords: 'No se encontraron registros',
                emptyTable: 'No hay datos disponibles',
                paginate: {
                    first: 'Primero',
                    previous: 'Anterior',
                    next: 'Siguiente',
                    last: 'Ultimo',
                },
            },
        });

        if (filtersForm) {
            let reloadTimeout;
            const reloadTable = () => {
                window.clearTimeout(reloadTimeout);
                reloadTimeout = window.setTimeout(() => dataTable.ajax.reload(), 180);
            };

            filtersForm.addEventListener('change', reloadTable);
            filtersForm.addEventListener('reset', () => {
                window.setTimeout(() => {
                    filtersForm.querySelectorAll('select[data-tom-select]').forEach((select) => {
                        select.tomselect?.clear(true);
                    });
                    dataTable.ajax.reload();
                }, 0);
            });
        }

        dataTable.on('xhr.dt', (_event, _settings, _json, xhr) => {
            if (xhr.status === 401 || xhr.status === 403 || xhr.responseURL?.includes('/login')) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Sin acceso',
                    text: 'No tienes permisos para cargar los datos de esta tabla o tu sesion expiro.',
                });
            }
        });
        table.dataset.datatableInitialized = '1';
    });
}

function initTomSelects(scope = document) {
    scope.querySelectorAll('select[data-tom-select]').forEach((select) => {
        if (select.tomselect) {
            return;
        }

        new TomSelect(select, {
            allowEmptyOption: select.dataset.allowEmptyOption !== 'false',
            create: false,
            dropdownParent: 'body',
            maxItems: select.multiple ? null : 1,
            placeholder: select.dataset.placeholder ?? 'Seleccionar',
            plugins: ['clear_button'],
            render: {
                no_results() {
                    return '<div class="no-results">Sin resultados</div>';
                },
            },
        });
    });
}

function initProductSiatSelectors(scope = document) {
    scope.querySelectorAll('[data-product-siat-form]').forEach((form) => {
        if (form.dataset.productSiatInitialized === '1') {
            return;
        }

        const activitySelect = form.querySelector('[data-product-siat-activity]');
        const productSelect = form.querySelector('[data-product-siat-code]');

        if (!activitySelect || !productSelect) {
            return;
        }

        const sourceOptions = Array.from(productSelect.options).map((option) => ({
            value: option.value,
            text: option.textContent,
            activityCode: option.dataset.activityCode || '',
        }));

        const syncProducts = () => {
            const activityCode = activitySelect.value;
            const currentValue = productSelect.value;
            const availableOptions = sourceOptions.filter((option) => (
                option.value !== '' && option.activityCode === activityCode
            ));
            const currentIsAvailable = availableOptions.some((option) => option.value === currentValue);

            if (productSelect.tomselect) {
                const tomSelect = productSelect.tomselect;

                tomSelect.clear(true);
                tomSelect.clearOptions();
                tomSelect.settings.placeholder = activityCode ? 'Seleccionar producto SIAT' : 'Selecciona una actividad primero';
                productSelect.setAttribute('placeholder', tomSelect.settings.placeholder);
                tomSelect.inputState();

                availableOptions.forEach((option, index) => {
                    tomSelect.addOption({
                        value: option.value,
                        text: option.text,
                        $order: index + 1,
                    });
                });

                if (currentValue && currentIsAvailable) {
                    tomSelect.setValue(currentValue, true);
                }

                if (!activityCode || availableOptions.length === 0) {
                    tomSelect.disable();
                } else {
                    tomSelect.enable();
                }

                tomSelect.refreshOptions(false);
                tomSelect.refreshItems();

                return;
            }

            Array.from(productSelect.options).forEach((option) => {
                if (option.value === '') {
                    option.textContent = activityCode ? 'Seleccionar producto SIAT' : 'Selecciona una actividad primero';
                    option.hidden = false;
                    return;
                }

                option.hidden = option.dataset.activityCode !== activityCode;
            });

            productSelect.disabled = !activityCode || availableOptions.length === 0;

            if (currentValue && !currentIsAvailable) {
                productSelect.value = '';
            }
        };

        activitySelect.addEventListener('change', syncProducts);

        if (activitySelect.tomselect) {
            activitySelect.tomselect.on('change', syncProducts);
        }

        syncProducts();
        form.dataset.productSiatInitialized = '1';
    });
}

function initInvoiceIssueForms(scope = document) {
    scope.querySelectorAll('[data-invoice-issue-form]').forEach((form) => {
        if (form.dataset.invoiceIssueInitialized === '1') {
            return;
        }

        const pointOfSaleSelect = form.querySelector('[data-invoice-point-of-sale]');
        const customerSelect = form.querySelector('[data-invoice-customer-select]');
        const productSelect = form.querySelector('[data-invoice-product-select]');
        const itemsBody = form.querySelector('[data-invoice-items]');
        const emptyRow = form.querySelector('[data-invoice-empty]');
        const activitySelect = form.querySelector('[name="economic_activity_code"]');
        const issuedAtInput = form.querySelector('[name="issued_at"]');
        const additionalDescriptionInput = form.querySelector('[name="additional_description"]');
        const quantityInput = form.querySelector('[data-invoice-quantity]');
        const unitPriceInput = form.querySelector('[data-invoice-unit-price]');
        const discountInput = form.querySelector('[data-invoice-discount]');
        const discountTypeInput = form.querySelector('[data-invoice-discount-type]');
        const totalDiscountInput = form.querySelector('[data-invoice-total-discount]');
        const totalDiscountTypeInput = form.querySelector('[data-invoice-total-discount-type]');
        const totalDiscountPercentageInput = form.querySelector('[data-invoice-total-discount-percentage]');
        const paymentMethodSelect = form.querySelector('[name="payment_method_code"]');
        const currencySelect = form.querySelector('[name="currency_code"]');
        const exchangeRateField = form.querySelector('[data-invoice-exchange-rate-field]');
        const exchangeRateInput = form.querySelector('[name="exchange_rate"]');
        const cardField = form.querySelector('[data-invoice-card-field]');
        const cardNumberInput = form.querySelector('[data-invoice-card-number]');
        const giftCardInput = form.querySelector('[data-invoice-gift-card]');
        const documentSectorCode = Number.parseInt(form.querySelector('[name="document_sector_code"]')?.value ?? '1', 10);
        const productUnit = form.querySelector('[data-product-unit]');
        const subtotalTarget = form.querySelector('[data-invoice-subtotal]');
        const totalTarget = form.querySelector('[data-invoice-total]');
        const taxableTotalTarget = form.querySelector('[data-invoice-taxable-total]');
        const fiscalStatus = form.querySelector('[data-invoice-fiscal-status]');
        const manualCafc = form.dataset.manualCafc === '1';
        const preserveIssuedAt = form.dataset.preserveIssuedAt === '1';
        let communicationOk = fiscalStatus?.dataset.communicationOk === '1';
        const cufdRequestUrl = fiscalStatus?.dataset.cufdRequestUrl;
        const refreshCufdOnSelection = fiscalStatus?.dataset.refreshCufdOnSelection === '1';
        const cuisStatus = form.querySelector('[data-cuis-status]');
        const cufdStatus = form.querySelector('[data-cufd-status]');
        const submitButton = form.querySelector('[data-invoice-submit]');
        const submitLabel = form.querySelector('[data-invoice-submit-label]');
        const communicationMessage = form.querySelector('[data-invoice-communication-message]');
        const items = [];

        const money = (amount) => `BO ${Number(amount || 0).toFixed(2)}`;
        const currentBoliviaDateTime = () => {
            const parts = new Intl.DateTimeFormat('en-CA', {
                timeZone: 'America/La_Paz',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
            }).formatToParts(new Date());
            const value = Object.fromEntries(parts.map(({ type, value: part }) => [type, part]));

            return `${value.year}-${value.month}-${value.day}T${value.hour}:${value.minute}`;
        };
        const numberValue = (input, fallback = 0) => {
            const value = Number.parseFloat(input?.value ?? '');

            return Number.isFinite(value) ? value : fallback;
        };

        const selectedValue = (select) => {
            const value = select?.tomselect ? select.tomselect.getValue() : select?.value;

            return Array.isArray(value) ? value[0] : value;
        };
        const selectedOption = (select) => {
            const value = selectedValue(select);

            if (!select || !value) {
                return null;
            }

            return Array.from(select.options).find((option) => option.value === String(value)) ?? null;
        };
        const updatePaymentMethod = () => {
            const usesCard = String(selectedValue(paymentMethodSelect) ?? '') === '2';
            const usesGiftCard = selectedOption(paymentMethodSelect)?.dataset.isGiftCard === '1';
            cardField?.classList.toggle('d-none', !usesCard);
            if (cardNumberInput) {
                cardNumberInput.required = usesCard;
                if (!usesCard) cardNumberInput.value = '';
            }
            if (giftCardInput) {
                giftCardInput.disabled = !usesGiftCard;
                if (!usesGiftCard) giftCardInput.value = '0.00';
            }
            updateTotals();
        };
        const updateCurrency = () => {
            const usesBolivianos = String(selectedValue(currencySelect) ?? '1') === '1';
            exchangeRateField?.classList.toggle('d-none', usesBolivianos);

            if (usesBolivianos && exchangeRateInput) {
                exchangeRateInput.value = '1.00';
            }
        };
        const customerOptionText = (customer) => {
            const complement = customer.document_complement ? `-${customer.document_complement}` : '';

            return `${customer.name} - ${customer.document_number}${complement}`;
        };
        const optionData = (option) => ({
            name: option?.dataset.name || '-',
            document: option?.dataset.document || '-',
            complement: option?.dataset.complement || '-',
            email: option?.dataset.email || '-',
            documentType: option?.dataset.documentType || '',
        });

        const setFiscalStatus = (target, ok, label, detail) => {
            if (!target) {
                return;
            }

            target.classList.toggle('is-ok', ok);
            target.classList.toggle('is-bad', !ok);

            const labelTarget = target.querySelector('[data-status-label]');
            const detailTarget = target.querySelector('[data-status-detail]');

            if (labelTarget) {
                labelTarget.textContent = label;
            }

            if (detailTarget) {
                detailTarget.textContent = ok ? '' : detail;
                detailTarget.classList.toggle('d-none', ok || !detail);
            }
        };

        const requestCufd = async (option) => {
            if (
                !cufdRequestUrl
                || !option?.value
                || String(option.value).startsWith('branch-')
                || option.dataset.cufdRequesting === '1'
                || option.dataset.cufdAttempted === '1'
            ) {
                return;
            }

            option.dataset.cufdRequesting = '1';
            option.dataset.cufdAttempted = '1';
            option.dataset.cufdDetail = 'Solicitando CUFD...';
            setFiscalStatus(cufdStatus, false, option.dataset.cufdLabel || 'CUFD', option.dataset.cufdDetail);

            try {
                const body = new FormData();
                body.append('sin_point_of_sale_id', option.value);

                const response = await fetch(cufdRequestUrl, {
                    method: 'POST',
                    body,
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json();

                if (!response.ok || (payload.success === false && !payload.contingency_suggested)) {
                    throw new Error(payload.message ?? 'No se pudo solicitar CUFD.');
                }

                if (payload.contingency_suggested) {
                    communicationOk = false;
                    if (fiscalStatus) fiscalStatus.dataset.communicationOk = '0';
                    option.dataset.cufdValid = payload.data?.cufd?.is_current ? '1' : '0';
                    option.dataset.cufdLabel = 'CUFD';
                    option.dataset.cufdDetail = option.dataset.cufdValid === '1'
                        ? ''
                        : 'No existe un CUFD vigente para emitir fuera de línea';
                    if (communicationMessage) {
                        communicationMessage.textContent = payload.message;
                        communicationMessage.classList.remove('d-none', 'alert-warning');
                        communicationMessage.classList.add('alert-danger');
                    }
                    Swal.fire({
                        icon: 'warning',
                        title: 'Emisión fuera de línea',
                        text: payload.message,
                    });
                    return;
                }

                option.dataset.cufdValid = payload.data?.cufd?.is_current ? '1' : '0';
                option.dataset.cufdLabel = 'CUFD';
                option.dataset.cufdDetail = option.dataset.cufdValid === '1' ? '' : 'CUFD no vigente';

                toast.fire({ icon: 'success', title: payload.message ?? 'CUFD generado correctamente.' });
            } catch (error) {
                option.dataset.cufdValid = '0';
                option.dataset.cufdLabel = 'CUFD';
                option.dataset.cufdDetail = 'CUFD no vigente';
                Swal.fire({ icon: 'error', title: 'CUFD', text: error.message });
            } finally {
                option.dataset.cufdRequesting = '0';
                updateFiscalReadiness();
            }
        };

        const updateFiscalReadiness = () => {
            const option = selectedOption(pointOfSaleSelect);
            const hasPointOfSale = Boolean(option?.value) && !String(option.value).startsWith('branch-');
            const cuisOk = hasPointOfSale && option?.dataset.cuisValid === '1';
            const cufdOk = hasPointOfSale && option?.dataset.cufdValid === '1';
            const recoveryBlocked = false;

            setFiscalStatus(
                cuisStatus,
                cuisOk,
                option?.dataset.cuisLabel || 'CUIS',
                option?.dataset.cuisDetail || 'CUIS no vigente',
            );
            setFiscalStatus(
                cufdStatus,
                cufdOk,
                option?.dataset.cufdLabel || 'CUFD',
                option?.dataset.cufdDetail || 'CUFD no vigente',
            );

            if (submitButton) {
                submitButton.disabled = manualCafc ? false : (!(cuisOk && cufdOk) || recoveryBlocked);
            }

            if (submitLabel) {
                submitLabel.textContent = manualCafc ? 'Transcribir' : (communicationOk ? 'Emitir' : 'Emitir fuera de linea');
            }

            if (communicationMessage) {
                communicationMessage.classList.toggle('d-none', communicationOk && !recoveryBlocked);
            }

            if (!manualCafc && communicationOk && hasPointOfSale && cuisOk && !cufdOk) {
                requestCufd(option);
            }
        };

        const handlePointOfSaleSelection = () => {
            const option = selectedOption(pointOfSaleSelect);

            if (communicationOk && refreshCufdOnSelection && option?.value && !String(option.value).startsWith('branch-')) {
                option.dataset.cufdAttempted = '0';
                option.dataset.cufdValid = '0';
                option.dataset.cufdDetail = 'CUFD pendiente de renovacion';
            }

            updateFiscalReadiness();
        };

        const updateCustomer = () => {
            const option = selectedOption(customerSelect);
            const customer = optionData(option);

            form.querySelector('[data-client-name]').textContent = customer.name;
            form.querySelector('[data-client-document]').textContent = customer.document;
            form.querySelector('[data-client-complement]').textContent = customer.complement;
            form.querySelector('[data-client-email]').textContent = customer.email;

            form.dispatchEvent(new CustomEvent('invoice:customer-selected', { detail: customer }));
        };

        const addCustomerOption = (customer) => {
            if (!customerSelect || !customer?.id) {
                return;
            }

            const value = String(customer.id);
            let option = customerSelect.querySelector(`option[value="${CSS.escape(value)}"]`);

            if (!option) {
                option = new Option(customerOptionText(customer), value, true, true);
                customerSelect.append(option);
            }

            option.textContent = customerOptionText(customer);
            option.dataset.name = customer.name ?? '';
            option.dataset.document = customer.document_number ?? '';
            option.dataset.complement = customer.document_complement ?? '';
            option.dataset.email = customer.email ?? '';
            option.dataset.customerCode = customer.customer_code ?? '';
            option.dataset.documentType = customer.identity_document_type_code ?? '';

            if (customerSelect.tomselect) {
                customerSelect.tomselect.addOption({
                    value,
                    text: option.textContent,
                    name: customer.name ?? '',
                    document_number: customer.document_number ?? '',
                    document_complement: customer.document_complement ?? '',
                    customer_code: customer.customer_code ?? '',
                    email: customer.email ?? '',
                    identity_document_type_code: customer.identity_document_type_code ?? '',
                });
                customerSelect.tomselect.refreshOptions(false);
                customerSelect.tomselect.setValue(value, true);
            } else {
                customerSelect.value = value;
            }

            updateCustomer();
        };

        const updateProduct = () => {
            const option = selectedOption(productSelect);

            if (unitPriceInput && option?.dataset.unitPrice) {
                unitPriceInput.value = Number(option.dataset.unitPrice).toFixed(2);
            }

            if (productUnit) {
                const unitCode = option?.dataset.unitCode;
                const unitDescription = option?.dataset.unitDescription;
                productUnit.textContent = unitDescription || (unitCode ? 'Unidad SIAT' : 'Seleccione un producto');
            }
        };

        const renderItems = () => {
            if (!itemsBody) {
                return;
            }

            itemsBody.querySelectorAll('[data-invoice-item-row]').forEach((row) => row.remove());
            emptyRow?.classList.toggle('d-none', items.length > 0);

            items.forEach((item, index) => {
                const row = document.createElement('tr');
                row.dataset.invoiceItemRow = '1';
                row.innerHTML = `
                    <td><strong></strong><div class="text-body-secondary small"></div></td>
                    <td class="text-end"><input class="form-control form-control-sm text-end ms-auto" type="number" min="0.00001" step="0.00001" style="width: 7rem" aria-label="Cantidad del item"></td>
                    <td></td>
                    <td class="text-end"></td>
                    <td class="text-end"></td>
                    <td class="text-end fw-semibold"></td>
                    <td class="text-end"><button class="btn btn-outline-danger btn-sm btn-icon" type="button" aria-label="Quitar item"><i class="ti ti-trash" aria-hidden="true"></i></button></td>
                `;

                row.children[0].querySelector('strong').textContent = item.code;
                row.children[0].querySelector('div').textContent = item.description;
                const rowQuantityInput = row.children[1].querySelector('input');
                rowQuantityInput.value = Number(item.quantity).toFixed(5).replace(/\.?0+$/, '');
                row.children[2].textContent = item.unit;
                row.children[3].textContent = money(item.unitPrice);
                row.children[4].textContent = money(item.discount);
                row.children[5].textContent = money(item.subtotal);
                rowQuantityInput.addEventListener('input', () => {
                    const quantity = numberValue(rowQuantityInput);
                    item.quantity = quantity;

                    if (quantity <= 0) {
                        rowQuantityInput.setCustomValidity('La cantidad debe ser mayor a cero.');
                        item.subtotal = 0;
                        row.children[5].textContent = money(0);
                        updateTotals();

                        return;
                    }

                    rowQuantityInput.setCustomValidity('');
                    const gross = quantity * item.unitPrice;
                    item.discount = item.discount_type === 'PERCENTAGE' ? gross * item.discount_percentage / 100 : item.discount_value;
                    item.subtotal = Math.max(0, gross - item.discount);
                    row.children[5].textContent = money(item.subtotal);
                    updateTotals();
                });
                row.querySelector('button')?.addEventListener('click', () => {
                    items.splice(index, 1);
                    renderItems();
                    updateTotals();
                });

                itemsBody.append(row);
            });
        };

        const updateTotals = () => {
            const subtotal = items.reduce((sum, item) => sum + item.subtotal, 0);
            const enteredDiscount = numberValue(totalDiscountInput);
            const totalDiscount = totalDiscountTypeInput?.value === 'PERCENTAGE' ? subtotal * enteredDiscount / 100 : enteredDiscount;
            if (totalDiscountPercentageInput) totalDiscountPercentageInput.value = totalDiscountTypeInput?.value === 'PERCENTAGE' ? enteredDiscount : '';
            const total = Math.max(0, subtotal - totalDiscount);
            const usesGiftCard = selectedOption(paymentMethodSelect)?.dataset.isGiftCard === '1';
            const giftCard = usesGiftCard ? Math.min(numberValue(giftCardInput), total) : 0;
            const taxableTotal = documentSectorCode === 8 ? 0 : Math.max(0, total - giftCard);

            if (subtotalTarget) {
                subtotalTarget.textContent = money(subtotal);
            }

            if (totalTarget) {
                totalTarget.textContent = money(total);
            }

            if (taxableTotalTarget) {
                taxableTotalTarget.textContent = money(taxableTotal);
            }
        };

        form.querySelector('[data-invoice-add-item]')?.addEventListener('click', () => {
            const option = selectedOption(productSelect);

            if (!option?.value) {
                Swal.fire({ icon: 'warning', title: 'Producto requerido', text: 'Selecciona un producto homologado para agregarlo al detalle.' });

                return;
            }

            const quantity = numberValue(quantityInput, 1);
            const unitPrice = numberValue(unitPriceInput);
            const discount = numberValue(discountInput);
            const discountType = discountTypeInput?.value || 'FIXED';
            const discountAmount = discountType === 'PERCENTAGE' ? quantity * unitPrice * discount / 100 : discount;
            const subtotal = Math.max(0, (quantity * unitPrice) - discountAmount);

            items.push({
                product_id: Number(option.value),
                code: option.dataset.internalCode || option.value,
                description: option.dataset.description || option.textContent.trim(),
                additional_description: additionalDescriptionInput?.value?.trim() || '',
                activity_code: option.dataset.activityCode || activitySelect?.value || '',
                siat_product_code: option.dataset.siatProductCode || '',
                measurement_unit_code: option.dataset.unitCode || '',
                quantity,
                unit: option.dataset.unitDescription || option.dataset.unitCode || '-',
                unit_price: unitPrice,
                unitPrice,
                discount: discountAmount,
                discount_value: discount,
                discount_type: discountType,
                discount_percentage: discountType === 'PERCENTAGE' ? discount : null,
                subtotal,
            });

            renderItems();
            updateTotals();

            if (quantityInput) {
                quantityInput.value = '1.00';
            }

            if (discountInput) {
                discountInput.value = '0.00';
            }
        });

        const resetInvoiceForm = () => {
            items.splice(0, items.length);
            form.reset();

            const issuanceKeyInput = form.querySelector('[name="issuance_key"]');

            if (issuanceKeyInput && globalThis.crypto?.randomUUID) {
                issuanceKeyInput.value = globalThis.crypto.randomUUID();
            }

            form.querySelectorAll('select[data-tom-select]').forEach((select) => {
                if (select === paymentMethodSelect || select === currencySelect) {
                    select.tomselect?.setValue('1', true);

                    return;
                }

                select.tomselect?.clear(true);
            });

            if (issuedAtInput && !preserveIssuedAt) {
                issuedAtInput.value = currentBoliviaDateTime();
            }

            updateCustomer();
            updateProduct();
            updateFiscalReadiness();
            renderItems();
            updateTotals();
            updatePaymentMethod();
            updateCurrency();
        };

        const submitInvoice = async () => {
            if (submitButton?.disabled) {
                return;
            }

            if (items.length === 0) {
                Swal.fire({ icon: 'warning', title: 'Detalle requerido', text: 'Agrega al menos un producto o servicio.' });

                return;
            }

            if (items.some((item) => !Number.isFinite(item.quantity) || item.quantity <= 0)) {
                Swal.fire({ icon: 'warning', title: 'Cantidad inválida', text: 'Todas las cantidades del detalle deben ser mayores a cero.' });

                return;
            }

            submitButton.disabled = true;
            submitButton.classList.add('disabled');

            try {
                if (issuedAtInput && !preserveIssuedAt) {
                    issuedAtInput.value = currentBoliviaDateTime();
                }

                const body = new FormData(form);
                body.set('items', JSON.stringify(items));

                const response = await fetch(form.action, {
                    method: 'POST',
                    body,
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json();

                if (response.status === 422) {
                    const errors = payload.errors ?? payload.data ?? {};
                    showFormErrors(form, errors);
                    Swal.fire({ icon: 'error', title: 'Validacion', text: validationSummary(errors, payload.message) });

                    return;
                }

                const invoice = payload.data?.invoice;

                if (payload.success) {
                    const offline = payload.decision === 'OFFLINE_DIGITAL';
                    resetInvoiceForm();
                    const result = await Swal.fire({
                        icon: 'success',
                        title: manualCafc ? 'Factura CAFC transcrita' : (offline ? 'Factura emitida fuera de linea' : 'Factura validada'),
                        text: manualCafc
                            ? (payload.message ?? 'La factura fue transcrita y quedó preparada para su regularización.')
                            : offline
                            ? `Factura ${invoice?.invoice_number ?? ''} emitida localmente y pendiente de sincronizacion con el SIN.`
                            : `Factura ${invoice?.invoice_number ?? ''} validada por el SIN. Codigo de recepcion: ${invoice?.reception_code ?? '-'}`,
                        confirmButtonText: invoice?.print_url ? 'Imprimir PDF' : 'Aceptar',
                        showDenyButton: Boolean(invoice?.xml_url),
                        denyButtonText: 'Ver XML',
                        showCancelButton: Boolean(invoice?.print_url),
                        cancelButtonText: 'Cerrar',
                    });

                    if (result.isConfirmed && invoice?.print_url) {
                        window.open(invoice.print_url, '_blank', 'noopener');
                    }

                    if (result.isDenied && invoice?.xml_url) {
                        window.open(invoice.xml_url, '_blank', 'noopener');
                    }

                    if (manualCafc && payload.redirect_url) {
                        window.location.assign(payload.redirect_url);
                    }

                    return;
                }

                const failedResult = await Swal.fire({
                    icon: 'warning',
                    title: invoice ? `Factura ${invoice.status_label ?? 'observada'}` : 'Emision bloqueada',
                    text: invoice
                        ? `Intento nro. ${invoice.attempted_invoice_number ?? '-'}. ${payload.message ?? 'El SIN devolvio observaciones para la factura.'}`
                        : (payload.message ?? 'No fue posible emitir la factura.'),
                    confirmButtonText: invoice?.contingency_url ? 'Registrar evento de contingencia' : 'Aceptar',
                    showCancelButton: Boolean(invoice?.contingency_url),
                    cancelButtonText: 'Cerrar',
                });

                if (failedResult.isConfirmed && invoice?.contingency_url) {
                    window.location.assign(invoice.contingency_url);
                }
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'Emision de factura', text: error.message });
            } finally {
                updateFiscalReadiness();
                submitButton.classList.remove('disabled');
            }
        };

        submitButton?.addEventListener('click', submitInvoice);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            submitInvoice();
        });

        form.querySelector('[data-invoice-clear]')?.addEventListener('click', () => {
            window.setTimeout(resetInvoiceForm, 0);
        });

        pointOfSaleSelect?.addEventListener('change', handlePointOfSaleSelection);
        customerSelect?.addEventListener('change', updateCustomer);
        productSelect?.addEventListener('change', updateProduct);
        pointOfSaleSelect?.tomselect?.on('change', handlePointOfSaleSelection);
        customerSelect?.tomselect?.on('change', updateCustomer);
        productSelect?.tomselect?.on('change', updateProduct);
        totalDiscountInput?.addEventListener('input', updateTotals);
        totalDiscountTypeInput?.addEventListener('change', updateTotals);
        giftCardInput?.addEventListener('input', updateTotals);
        paymentMethodSelect?.addEventListener('change', updatePaymentMethod);
        paymentMethodSelect?.tomselect?.on('change', updatePaymentMethod);
        currencySelect?.addEventListener('change', updateCurrency);
        currencySelect?.tomselect?.on('change', updateCurrency);
        cardNumberInput?.addEventListener('input', () => {
            const digits = cardNumberInput.value.replace(/\D/g, '').slice(0, 16);
            cardNumberInput.value = digits.replace(/(.{4})/g, '$1 ').trim();
        });
        document.addEventListener('ajax-form:success', (event) => {
            const sourceForm = event.detail?.form;
            const customer = event.detail?.payload?.data?.customer;

            if (!sourceForm?.matches('[data-invoice-customer-create]') || !customer) {
                return;
            }

            addCustomerOption(customer);
        });

        updateCustomer();
        updateProduct();
        updateFiscalReadiness();
        renderItems();
        updateTotals();
        updatePaymentMethod();
        updateCurrency();
        form.dataset.invoiceIssueInitialized = '1';
    });
}

function initCharacterCounters(scope = document) {
    scope.querySelectorAll('[data-character-counter]').forEach((field) => {
        if (field.dataset.characterCounterInitialized === '1') {
            return;
        }

        const target = scope.querySelector(field.dataset.characterCounter) ?? document.querySelector(field.dataset.characterCounter);

        if (!target) {
            return;
        }

        const min = Number(field.getAttribute('minlength') || 0);
        const max = Number(field.getAttribute('maxlength') || 0);
        const update = () => {
            const length = field.value.length;
            const minimum = min ? ` / minimo ${min}` : '';
            const maximum = max ? ` / maximo ${max}` : '';
            target.textContent = `${length} caracteres${minimum}${maximum}`;
            target.classList.toggle('text-danger', (min && length < min) || (max && length > max));
            target.classList.toggle('text-success', (!min || length >= min) && (!max || length <= max));
        };

        field.addEventListener('input', update);
        field.dataset.characterCounterInitialized = '1';
        update();
    });
}

function initImagePickers(scope = document) {
    scope.querySelectorAll('input[type="file"][accept*="image"]').forEach((input) => {
        if (input.dataset.imagePickerInitialized === '1') {
            return;
        }

        input.dataset.imagePickerInitialized = '1';
        const preview = document.createElement('div');
        preview.className = 'image-picker-preview';
        preview.setAttribute('aria-live', 'polite');
        input.insertAdjacentElement('afterend', preview);

        const render = () => {
            preview.querySelectorAll('img').forEach((image) => URL.revokeObjectURL(image.src));
            preview.replaceChildren();

            [...input.files].forEach((file, index) => {
                const item = document.createElement('div');
                item.className = 'image-picker-item';

                const image = document.createElement('img');
                image.src = URL.createObjectURL(file);
                image.alt = `Vista previa de ${file.name}`;

                const details = document.createElement('div');
                details.className = 'image-picker-details';
                details.innerHTML = `<span class="image-picker-name"></span><span class="text-body-secondary small">${formatFileSize(file.size)} - se guardara como WebP</span>`;
                details.querySelector('.image-picker-name').textContent = file.name;

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn-outline-danger btn-icon image-picker-remove';
                remove.setAttribute('aria-label', `Quitar ${file.name} antes de guardar`);
                remove.innerHTML = '<i class="ti ti-trash" aria-hidden="true"></i>';
                remove.addEventListener('click', () => removeSelectedImage(input, index, render));

                item.append(image, details, remove);
                preview.append(item);
            });
        };

        input.addEventListener('change', render);
        render();
    });
}

function removeSelectedImage(input, removedIndex, render) {
    const transfer = new DataTransfer();

    [...input.files].forEach((file, index) => {
        if (index !== removedIndex) {
            transfer.items.add(file);
        }
    });

    input.files = transfer.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    render();
}

function formatFileSize(bytes) {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function initPermissionMatrices(scope = document) {
    scope.querySelectorAll('[data-permission-matrix]').forEach((matrix) => {
        if (matrix.dataset.permissionMatrixInitialized === '1') {
            return;
        }

        const checkboxes = [...matrix.querySelectorAll('[data-permission-checkbox]')];
        const modules = [...matrix.querySelectorAll('[data-permission-module]')];
        const search = matrix.querySelector('[data-permission-search]');
        const empty = matrix.querySelector('[data-permission-empty]');
        const selectedCount = matrix.querySelector('[data-permission-selected-count]');
        const visibleOptions = () => checkboxes.filter((checkbox) => !checkbox.closest('[data-permission-option]')?.classList.contains('d-none'));

        const update = () => {
            if (selectedCount) {
                selectedCount.textContent = String(checkboxes.filter((checkbox) => checkbox.checked).length);
            }

            modules.forEach((module) => {
                const moduleCheckboxes = [...module.querySelectorAll('[data-permission-checkbox]')];
                const moduleCount = module.querySelector('[data-module-selected-count]');
                const moduleButton = module.querySelector('[data-permission-select-module]');
                const checked = moduleCheckboxes.filter((checkbox) => checkbox.checked).length;

                if (moduleCount) {
                    moduleCount.textContent = String(checked);
                }

                if (moduleButton) {
                    const allSelected = checked === moduleCheckboxes.length;
                    moduleButton.textContent = allSelected ? 'Desactivar todos' : 'Activar todos';
                    moduleButton.classList.toggle('btn-ghost-danger', allSelected);
                    moduleButton.classList.toggle('btn-ghost-primary', !allSelected);
                }
            });
        };

        const filter = () => {
            const query = search?.value.trim().toLocaleLowerCase('es') ?? '';
            let visibleModules = 0;

            modules.forEach((module) => {
                const options = [...module.querySelectorAll('[data-permission-option]')];
                const moduleMatches = module.dataset.searchText?.includes(query) ?? false;
                let visibleOptionCount = 0;

                options.forEach((option) => {
                    const matches = !query || moduleMatches || option.dataset.searchText?.includes(query);
                    option.classList.toggle('d-none', !matches);
                    visibleOptionCount += matches ? 1 : 0;
                });

                const visible = visibleOptionCount > 0;
                module.classList.toggle('d-none', !visible);
                visibleModules += visible ? 1 : 0;

                if (query && visible) {
                    bootstrap.Collapse.getOrCreateInstance(module.querySelector('[data-permission-module-panel]'), { toggle: false }).show();
                }
            });

            empty?.classList.toggle('d-none', visibleModules > 0);
        };

        checkboxes.forEach((checkbox) => checkbox.addEventListener('change', update));
        search?.addEventListener('input', filter);

        matrix.querySelector('[data-permission-select-visible]')?.addEventListener('click', () => {
            visibleOptions().forEach((checkbox) => {
                checkbox.checked = true;
            });
            update();
        });

        matrix.querySelector('[data-permission-clear]')?.addEventListener('click', () => {
            checkboxes.forEach((checkbox) => {
                checkbox.checked = false;
            });
            update();
        });

        modules.forEach((module) => {
            module.querySelector('[data-permission-select-module]')?.addEventListener('click', () => {
                const moduleCheckboxes = [...module.querySelectorAll('[data-permission-checkbox]')];
                const selectAll = moduleCheckboxes.some((checkbox) => !checkbox.checked);

                moduleCheckboxes.forEach((checkbox) => {
                    checkbox.checked = selectAll;
                });
                update();
            });
        });

        matrix.dataset.permissionMatrixInitialized = '1';
        update();
    });
}

function initLoginForm(scope = document) {
    const form = scope.querySelector('[data-login-form]');

    if (!form || form.dataset.loginInitialized === '1') {
        return;
    }

    const password = form.querySelector('#password');
    const passwordToggle = form.querySelector('[data-password-toggle]');
    const passwordIcon = form.querySelector('[data-password-toggle-icon]');
    const submit = form.querySelector('[data-login-submit]');
    const submitLabel = form.querySelector('[data-login-submit-label]');
    const spinner = form.querySelector('[data-login-spinner]');
    const arrow = form.querySelector('[data-login-arrow]');

    passwordToggle?.addEventListener('click', () => {
        const showing = password?.type === 'text';

        if (password) {
            password.type = showing ? 'password' : 'text';
            password.focus({ preventScroll: true });
        }

        passwordToggle.setAttribute('aria-label', showing ? 'Mostrar contrasena' : 'Ocultar contrasena');
        passwordIcon?.classList.toggle('ti-eye', showing);
        passwordIcon?.classList.toggle('ti-eye-off', !showing);
    });

    form.addEventListener('submit', () => {
        if (submit) {
            submit.disabled = true;
        }

        form.setAttribute('aria-busy', 'true');
        spinner?.classList.remove('d-none');
        arrow?.classList.add('d-none');

        if (submitLabel) {
            submitLabel.textContent = 'Verificando acceso...';
        }
    });

    form.dataset.loginInitialized = '1';
}

function initUserDropdowns(scope = document) {
    scope.querySelectorAll('[data-user-dropdown-toggle]').forEach((toggle) => {
        if (toggle.dataset.dropdownInitialized === '1') {
            return;
        }

        const dropdown = bootstrap.Dropdown.getOrCreateInstance(toggle, {
            autoClose: true,
            popperConfig: {
                strategy: 'fixed',
            },
        });

        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            dropdown.toggle();
        });

        toggle.dataset.dropdownInitialized = '1';
    });
}

function initSidebarToggle() {
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.querySelector('.app-sidebar');

    if (!toggle || toggle.dataset.sidebarToggleInitialized === '1') {
        return;
    }

    const icon = toggle.querySelector('i');
    document.querySelectorAll('.app-sidebar .nav-link, .app-sidebar .app-menu-toggle').forEach((item) => {
        const label = item.querySelector('.nav-link-title')?.textContent?.trim();

        if (label && !item.getAttribute('title')) {
            item.setAttribute('title', label);
        }
    });

    const syncState = () => {
        const collapsed = document.body.classList.contains('app-sidebar-collapsed');
        toggle.setAttribute('aria-label', collapsed ? 'Expandir menu' : 'Replegar menu');
        toggle.setAttribute('title', collapsed ? 'Expandir menu' : 'Replegar menu');

        if (icon) {
            icon.className = collapsed ? 'ti ti-layout-sidebar-left-expand' : 'ti ti-layout-sidebar-left-collapse';
        }
    };

    toggle.addEventListener('click', () => {
        document.body.classList.toggle('app-sidebar-collapsed');
        document.body.classList.remove('app-sidebar-peek');
        localStorage.setItem('app-sidebar-collapsed', document.body.classList.contains('app-sidebar-collapsed') ? '1' : '0');
        syncState();
    });

    const openPeek = () => {
        if (document.body.classList.contains('app-sidebar-collapsed')) {
            document.body.classList.add('app-sidebar-peek');
        }
    };

    const closePeek = () => {
        document.body.classList.remove('app-sidebar-peek');
    };

    sidebar?.addEventListener('click', (event) => {
        if (!document.body.classList.contains('app-sidebar-collapsed')) {
            return;
        }

        openPeek();

        const link = event.target.closest('a.nav-link');
        const toggleButton = event.target.closest('.app-menu-toggle');

        if (link && !toggleButton) {
            closePeek();
        }
    }, true);

    document.addEventListener('click', (event) => {
        if (!document.body.classList.contains('app-sidebar-peek')) {
            return;
        }

        if (event.target.closest('.app-sidebar') || event.target.closest('[data-sidebar-toggle]')) {
            return;
        }

        closePeek();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closePeek();
        }
    });

    syncState();
    toggle.dataset.sidebarToggleInitialized = '1';
}

function initializeUi(scope = document) {
    disableBusinessFormAutocomplete(scope);
    initTomSelects(scope);
    initProductSiatSelectors(scope);
    initInvoiceIssueForms(scope);
    initAdminDataTables(scope);
    initCharacterCounters(scope);
    initPermissionMatrices(scope);
    initLoginForm(scope);
    initImagePickers(scope);
    initUserDropdowns(scope);
}

showInitialAlerts();
initializeUi();
initSidebarToggle();

document.addEventListener('click', (event) => {
    const modalTrigger = event.target.closest('[data-modal-url]');

    if (modalTrigger) {
        event.preventDefault();
        openAjaxModal(modalTrigger);

        return;
    }

    const autoSubmitField = event.target.closest('[data-auto-submit-form]');

    if (autoSubmitField) {
        autoSubmitField.closest('form')?.requestSubmit();
    }
});

document.addEventListener('submit', (event) => {
    const singleSubmitForm = event.target.closest('form[data-disable-on-submit]');

    if (singleSubmitForm) {
        if (singleSubmitForm.dataset.submitting === '1') {
            event.preventDefault();

            return;
        }

        singleSubmitForm.dataset.submitting = '1';
        singleSubmitForm.setAttribute('aria-busy', 'true');
        const submitButton = singleSubmitForm.querySelector('button[type="submit"]');

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.querySelector('span')?.replaceChildren(submitButton.dataset.submittingLabel ?? 'Procesando…');
        }
    }

    const ajaxForm = event.target.closest('[data-ajax-form]');
    const deleteForm = event.target.closest('[data-confirm-delete]');
    const actionForm = event.target.closest('[data-confirm-action]');

    if (deleteForm) {
        event.preventDefault();
        confirmDelete(deleteForm);

        return;
    }

    if (actionForm) {
        event.preventDefault();
        confirmAction(actionForm);

        return;
    }

    if (ajaxForm) {
        event.preventDefault();
        submitAjaxForm(ajaxForm);
    }
});
