(function() {
    'use strict';

    var config = window.qigeoExternalKeywordData || null;
    var form = document.getElementById('qigeo-external-form');
    var generateButton = document.getElementById('qigeo-generate-external-btn');
    var saveButton = document.getElementById('qigeo-save-external-btn');
    var feedback = document.getElementById('qigeo-external-feedback');

    if (!config || !form || !generateButton || !saveButton || !feedback) {
        return;
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function normalizePlatformKey(value) {
        if (window.QiGeoAdmin && typeof window.QiGeoAdmin.normalizePlatformKey === 'function') {
            return window.QiGeoAdmin.normalizePlatformKey(value);
        }
        var text = typeof value === 'string' ? value.toLowerCase().trim() : '';
        if (!text) {
            return '';
        }

        var aliases = {
            doubao: ['doubao', '豆包', '火山', '方舟', 'ark', 'volcengine'],
            deepseek: ['deepseek', 'deep seek', '深度求索', '深度搜索', '深度思考'],
            openai: ['openai', 'open ai', 'chatgpt', 'chat gpt', 'gpt'],
            qwen: ['qwen', '通义', '千问', '阿里云百炼', '百炼', 'dashscope']
        };

        return Object.keys(aliases).find(function(key) {
            return aliases[key].some(function(alias) {
                return text.indexOf(String(alias).toLowerCase()) !== -1;
            });
        }) || '';
    }

    function syncModelWithPlatform() {
        var platformInput = byId('qigeo-target-platform');
        var modelSelect = byId('qigeo-model-id');
        if (!platformInput || !modelSelect) {
            return;
        }

        var platformKey = normalizePlatformKey(platformInput.value);
        if (!platformKey) {
            return;
        }

        var currentOption = modelSelect.options[modelSelect.selectedIndex];
        if (currentOption && currentOption.dataset && currentOption.dataset.providerType && currentOption.dataset.providerType !== platformKey) {
            return;
        }

        var fallbackOption = null;
        Array.prototype.some.call(modelSelect.options, function(option) {
            if (!option || !option.value) {
                return false;
            }
            if (option.dataset && option.dataset.providerType && option.dataset.providerType !== platformKey) {
                modelSelect.value = option.value;
                return true;
            }
            if (!fallbackOption && option.dataset && option.dataset.providerType === platformKey) {
                fallbackOption = option;
            }
            return false;
        });

        if (fallbackOption && modelSelect.value !== fallbackOption.value && (!currentOption || !currentOption.value)) {
            modelSelect.value = fallbackOption.value;
        }
    }

    function setFeedback(message, isError) {
        feedback.textContent = message || '';
        feedback.classList.toggle('is-error', !!isError);
        feedback.classList.toggle('is-success', !!message && !isError);
    }

    function setBusy(isBusy) {
        generateButton.disabled = isBusy;
        saveButton.disabled = isBusy;
    }

    function joinLines(items) {
        if (!Array.isArray(items)) {
            return '';
        }
        return items
            .filter(function(item) {
                return typeof item === 'string' && item.trim() !== '';
            })
            .join('\n');
    }

    function joinFaqLines(items) {
        if (!Array.isArray(items)) {
            return '';
        }
        return items.map(function(item) {
            if (!item || typeof item !== 'object') {
                return '';
            }
            var q = typeof item.q === 'string' ? item.q.trim() : '';
            var a = typeof item.a === 'string' ? item.a.trim() : '';
            if (!q || !a) {
                return '';
            }
            return q + ' || ' + a;
        }).filter(Boolean).join('\n');
    }

    function joinSourceLines(items) {
        if (!Array.isArray(items)) {
            return '';
        }
        return items.map(function(item) {
            if (typeof item === 'string') {
                return item.trim();
            }
            if (!item || typeof item !== 'object') {
                return '';
            }
            var title = typeof item.title === 'string' ? item.title.trim() : '';
            var url = typeof item.url === 'string' ? item.url.trim() : '';
            var sourceType = typeof item.source_type === 'string' ? item.source_type.trim() : '';
            var claim = typeof item.claim === 'string' ? item.claim.trim() : '';
            var publishedAt = typeof item.published_at === 'string' ? item.published_at.trim() : '';
            var official = item.is_official ? '是' : '否';
            var priority = typeof item.priority === 'string' ? item.priority.trim() : '';
            if (!title && url && !sourceType && !claim && !publishedAt && !priority && !item.is_official) {
                return url;
            }
            if (!title && !url && !sourceType && !claim && !publishedAt && !priority) {
                return '';
            }
            return title + ' || ' + url + ' || ' + sourceType + ' || ' + claim + ' || ' + publishedAt + ' || ' + official + ' || ' + priority;
        }).filter(Boolean).join('\n');
    }

    function fillResults(result) {
        if (!result || typeof result !== 'object') {
            return;
        }

        var workflowMetaInput = byId('qigeo-workflow-meta-json');
        if (workflowMetaInput) {
            workflowMetaInput.value = result.workflow && typeof result.workflow === 'object'
                ? JSON.stringify(result.workflow)
                : '';
        }

        var modelSelect = byId('qigeo-model-id');
        if (modelSelect) {
            if (result.workflow && result.workflow.draft_model && typeof result.workflow.draft_model.model_id === 'string' && result.workflow.draft_model.model_id) {
                modelSelect.value = result.workflow.draft_model.model_id;
            } else if (typeof result.model_id === 'string' && result.model_id) {
                modelSelect.value = result.model_id;
            }
        }

        var fields = {
            'qigeo-result-summary': result.summary || '',
            'qigeo-result-positioning': result.recommendation_positioning || '',
            'qigeo-result-intent': joinLines(result.intent_breakdown),
            'qigeo-result-angles': joinLines(result.answer_angles),
            'qigeo-result-phrases': joinLines(result.mention_phrases),
            'qigeo-result-examples': joinLines(result.natural_mention_examples),
            'qigeo-result-faq': joinFaqLines(result.faq),
            'qigeo-result-assets': joinLines(result.content_assets),
            'qigeo-result-trust': joinLines(result.trust_signals),
            'qigeo-result-citations': joinSourceLines(result.citation_sources),
            'qigeo-result-source-gaps': joinLines(result.source_gaps),
            'qigeo-result-source-assets': joinLines(result.publishable_source_assets),
            'qigeo-result-claim-map': joinLines(result.claim_to_source_map),
            'qigeo-result-risk-claims': joinLines(result.risk_claims),
            'qigeo-result-risks': joinLines(result.risk_notes),
            'qigeo-result-steps': joinLines(result.execution_steps)
        };

        Object.keys(fields).forEach(function(id) {
            var element = byId(id);
            if (element) {
                element.value = fields[id];
            }
        });
    }

    async function postForm(action, nonce) {
        var body = new FormData(form);
        body.append('action', action);
        body.append('nonce', nonce);

        var response = await fetch(config.ajaxUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        });

        var json = await response.json();
        if (!json || typeof json !== 'object') {
            throw new Error('服务器返回异常，请稍后重试');
        }

        if (!json.success) {
            var message = json.data && json.data.message ? json.data.message : '请求失败，请稍后重试';
            throw new Error(message);
        }

        return json.data || {};
    }

    function updateUrl(recordId) {
        if (!recordId || !window.history || !window.history.replaceState) {
            return;
        }

        var url = new URL(window.location.href);
        url.searchParams.set('page', 'qigeo-external-keyword');
        url.searchParams.set('action', 'edit');
        url.searchParams.set('id', String(recordId));
        window.history.replaceState({}, '', url.toString());
    }

    generateButton.addEventListener('click', async function() {
        try {
            setBusy(true);
            syncModelWithPlatform();
            setFeedback(config.messages && config.messages.generating ? config.messages.generating : '正在生成...', false);
            var data = await postForm('qigeo_generate_external_keyword', config.generateNonce);
            fillResults(data.result || {});
            setFeedback(data.message || (config.messages && config.messages.generateDone) || '已生成', false);
        } catch (error) {
            setFeedback(error && error.message ? error.message : '生成失败，请稍后重试', true);
        } finally {
            setBusy(false);
        }
    });

    saveButton.addEventListener('click', async function() {
        try {
            setBusy(true);
            syncModelWithPlatform();
            setFeedback(config.messages && config.messages.saving ? config.messages.saving : '正在保存...', false);
            var data = await postForm('qigeo_save_external_keyword', config.saveNonce);
            var recordIdInput = byId('qigeo-external-record-id');
            if (recordIdInput && data.record_id) {
                recordIdInput.value = String(data.record_id);
            }
            if (data.record_id) {
                updateUrl(data.record_id);
            }
            setFeedback(data.message || (config.messages && config.messages.saveDone) || '已保存', false);
        } catch (error) {
            setFeedback(error && error.message ? error.message : '保存失败，请稍后重试', true);
        } finally {
            setBusy(false);
        }
    });

    var platformInput = byId('qigeo-target-platform');
    if (platformInput) {
        platformInput.addEventListener('change', syncModelWithPlatform);
        platformInput.addEventListener('blur', syncModelWithPlatform);
        syncModelWithPlatform();
    }
})();
