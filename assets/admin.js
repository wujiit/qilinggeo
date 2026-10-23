(function() {
    'use strict';

    window.QiGeoAdmin = window.QiGeoAdmin || {};

    window.QiGeoAdmin.platformAliases = {
        doubao: ['doubao', '豆包', '火山', '方舟', 'ark', 'volcengine'],
        deepseek: ['deepseek', 'deep seek', '深度求索', '深度搜索', '深度思考'],
        openai: ['openai', 'open ai', 'chatgpt', 'chat gpt', 'gpt'],
        qwen: ['qwen', '通义', '千问', '阿里云百炼', '百炼', 'dashscope']
    };

    window.QiGeoAdmin.normalizePlatformKey = function(value) {
        var text = typeof value === 'string' ? value.toLowerCase().trim() : '';
        if (!text) {
            return '';
        }
        var aliases = window.QiGeoAdmin.platformAliases;
        return Object.keys(aliases).find(function(key) {
            return aliases[key].some(function(alias) {
                return text.indexOf(String(alias).toLowerCase()) !== -1;
            });
        }) || '';
    };

    window.QiGeoAdmin.syncModelWithPlatform = function(platformInput, modelSelect) {
        if (!platformInput || !modelSelect) {
            return;
        }
        var platformKey = window.QiGeoAdmin.normalizePlatformKey(platformInput.value);
        if (!platformKey) {
            return;
        }
        var currentOption = modelSelect.options[modelSelect.selectedIndex];
        if (currentOption && currentOption.dataset && currentOption.dataset.providerType === platformKey) {
            return;
        }
        var matchedOption = null;
        Array.prototype.some.call(modelSelect.options, function(option) {
            if (!option || !option.value || !option.dataset) {
                return false;
            }
            if (option.dataset.providerType === platformKey) {
                matchedOption = option;
                return true;
            }
            return false;
        });
        if (matchedOption) {
            modelSelect.value = matchedOption.value;
        }
    };

    function getNextIndex(tableSelector, fieldName) {
        var rows = document.querySelectorAll(tableSelector + ' tbody tr');
        var max = -1;
        rows.forEach(function(row) {
            var input = row.querySelector('input[name^="' + fieldName + '["]');
            if (!input) {
                return;
            }
            var match = input.name.match(new RegExp(fieldName + '\\[(\\d+)\\]'));
            if (match && match[1]) {
                var n = parseInt(match[1], 10);
                if (!Number.isNaN(n) && n > max) {
                    max = n;
                }
            }
        });
        return max + 1;
    }

    function addRow(templateId, tableSelector, fieldName) {
        var template = document.getElementById(templateId);
        var tbody = document.querySelector(tableSelector + ' tbody');
        if (!template || !tbody) {
            return;
        }

        var index = getNextIndex(tableSelector, fieldName);
        var html = template.innerHTML.replace(/__INDEX__/g, String(index));
        var temp = document.createElement('tbody');
        temp.innerHTML = html.trim();
        if (temp.firstElementChild) {
            tbody.appendChild(temp.firstElementChild);
        }
    }

    function getNamedField(row, key) {
        return row ? row.querySelector('[name$="[' + key + ']"]') : null;
    }

    function getFieldValue(row, key) {
        var field = getNamedField(row, key);
        return field ? field.value.trim() : '';
    }

    function findModelNameForProvider(providerId) {
        if (!providerId) {
            return '';
        }

        var fallback = '';
        var rows = document.querySelectorAll('#qigeo-model-table tbody tr');
        rows.forEach(function(row) {
            if (fallback) {
                return;
            }

            var rowProviderId = getFieldValue(row, 'provider_id');
            var modelName = getFieldValue(row, 'model');
            if (rowProviderId !== providerId || !modelName) {
                return;
            }

            var enabled = getNamedField(row, 'enabled');
            if (!enabled || enabled.checked) {
                fallback = modelName;
            }
        });

        if (fallback) {
            return fallback;
        }

        rows.forEach(function(row) {
            if (fallback) {
                return;
            }

            var rowProviderId = getFieldValue(row, 'provider_id');
            var modelName = getFieldValue(row, 'model');
            if (rowProviderId === providerId && modelName) {
                fallback = modelName;
            }
        });

        return fallback;
    }

    function postForm(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function(res) {
            return res.json();
        });
    }

    function copyTextToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function(resolve, reject) {
            var input = document.createElement('textarea');
            input.value = text;
            input.setAttribute('readonly', 'readonly');
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.focus();
            input.select();
            try {
                var ok = document.execCommand('copy');
                document.body.removeChild(input);
                if (ok) {
                    resolve();
                } else {
                    reject(new Error('copy failed'));
                }
            } catch (err) {
                document.body.removeChild(input);
                reject(err);
            }
        });
    }

    function bindEvents() {
        var addProviderBtn = document.getElementById('qigeo-add-provider');
        var addModelBtn = document.getElementById('qigeo-add-model');

        if (addProviderBtn) {
            addProviderBtn.addEventListener('click', function() {
                addRow('qigeo-provider-row-template', '#qigeo-provider-table', 'providers');
            });
        }

        if (addModelBtn) {
            addModelBtn.addEventListener('click', function() {
                addRow('qigeo-model-row-template', '#qigeo-model-table', 'models');
            });
        }

        document.querySelectorAll('.qigeo-copy-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                var targetId = button.getAttribute('data-copy-target');
                var feedbackId = button.getAttribute('data-feedback-target');
                var target = targetId ? document.getElementById(targetId) : null;
                var feedback = feedbackId ? document.getElementById(feedbackId) : null;
                if (!target) {
                    if (feedback) {
                        feedback.textContent = '复制失败，请手动复制';
                    }
                    return;
                }
                copyTextToClipboard(target.value).then(function() {
                    if (feedback) {
                        feedback.textContent = '已复制到剪贴板';
                    }
                }).catch(function() {
                    if (feedback) {
                        feedback.textContent = '复制失败，请手动复制';
                    }
                });
            });
        });

        document.addEventListener('click', function(e) {
            var testButton = e.target.closest('.qigeo-test-provider');
            if (testButton) {
                var row = testButton.closest('tr');
                var result = row ? row.querySelector('.qigeo-provider-test-result') : null;
                if (!row || !window.qigeoAdminData || !qigeoAdminData.ajaxUrl) {
                    return;
                }

                var providerId = getFieldValue(row, 'id');
                var body = new URLSearchParams();
                body.append('action', 'qigeo_test_provider_connection');
                body.append('nonce', qigeoAdminData.testProviderNonce || '');
                body.append('provider_id', providerId);
                body.append('name', getFieldValue(row, 'name'));
                body.append('type', getFieldValue(row, 'type'));
                body.append('base_url', getFieldValue(row, 'base_url'));
                body.append('api_key', getFieldValue(row, 'api_key'));
                body.append('model_name', findModelNameForProvider(providerId));

                testButton.disabled = true;
                testButton.textContent = qigeoAdminData.testingProviderText || '测试中...';
                if (result) {
                    result.style.color = '#646970';
                    result.textContent = '正在测试平台连通性...';
                }

                postForm(qigeoAdminData.ajaxUrl, body)
                    .then(function(data) {
                        if (result) {
                            var ok = data && data.success;
                            result.style.color = ok ? '#0f5132' : '#b32d2e';
                            result.textContent = data && data.data && data.data.message ? data.data.message : (ok ? '连通成功' : '连通失败');
                        }
                    })
                    .catch(function() {
                        if (result) {
                            result.style.color = '#b32d2e';
                            result.textContent = '测试请求失败，请稍后重试。';
                        }
                    })
                    .finally(function() {
                        testButton.disabled = false;
                        testButton.textContent = qigeoAdminData.testProviderText || '测试';
                    });
                return;
            }

            if (!e.target.classList.contains('qigeo-remove-row')) {
                return;
            }
            var tr = e.target.closest('tr');
            if (tr) {
                tr.remove();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindEvents);
    } else {
        bindEvents();
    }
})();
