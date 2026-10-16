<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Helper;

use Joomla\CMS\Document\Document;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * The window that tells who sent a form that the server is at work on the images, and how far it
 * is. It only tells: the form is sent as it would be without it.
 *
 * The page makes up a token and sends it with the form. The request writes how far it is under
 * the token, and the page asks the system plugin for it once a second. Where requests of one
 * session wait for each other, the answers come when the work is done: the window then says that
 * the work goes on, and no more.
 *
 * A form can be sent without the page being loaded again, which is how FLEXIcontent applies. The
 * editor goes on showing what its author sent then, and that is not what was stored when URLs
 * were replaced in it: the page is loaded again, to show the content as it is.
 */
final class WaitDialog
{
    /** Name of the field of the form that holds the token */
    public const FIELD = 'mediaaudit_progress';

    /**
     * @param  string[]  $tasks   The tasks of the form that take long. One that starts with a dot
     *                            stands for every task that ends in it, like ".apply".
     * @param  string    $prefix  Of the keys of the language, of the extension that adds the window.
     * @param  array{external?: bool, local?: bool}|null  $detect  What the content of the form has
     *                            to hold for the window to show: images of other sites, images of
     *                            this site that can be converted. Null to show it every time.
     */
    public static function add(Document $document, array $tasks, string $prefix = 'COM_MEDIAAUDIT', ?array $detect = null): void
    {
        if ($document->getType() !== 'html') {
            return;
        }

        $texts = [];

        foreach (['TITLE', 'TEXT', 'PROGRESS', 'SLOW', 'CLOSE', 'RELOADED', 'STAGE_LOCALIZE', 'STAGE_CONVERT', 'STAGE_REWRITE', 'STAGE_SCAN', 'STAGE_SAVE'] as $key) {
            $texts[strtolower($key)] = Text::_($prefix . '_WAIT_' . $key);
        }

        $document->addScriptOptions('mediaaudit.wait', [
            'tasks'  => array_values($tasks),
            'field'  => self::FIELD,
            'url'    => Uri::base() . 'index.php?option=com_ajax&plugin=mediaaudit&group=system&format=json',
            'detect' => $detect,
            'texts'  => $texts,
        ]);

        $document->addStyleDeclaration(self::styles());
        $document->getWebAssetManager()->addInlineScript(self::script());
    }

    public static function styles(): string
    {
        return '.mediaaudit-wait{border:0;border-radius:10px;padding:28px 32px;max-width:min(440px,calc(100vw - 32px));width:100%;box-sizing:border-box;'
            . 'background:var(--body-bg,#fff);color:var(--body-color,#1f2937);box-shadow:0 12px 40px rgba(0,0,0,.35);text-align:center}'
            . '.mediaaudit-wait::backdrop{background:rgba(10,20,40,.55)}'
            . '.mediaaudit-wait h2{font-size:1.25rem;margin:16px 0 8px;color:inherit}'
            . '.mediaaudit-wait p{margin:0 0 12px;font-size:.9375rem}'
            . '.mediaaudit-wait-stage{font-weight:600}'
            . '.mediaaudit-wait-current{font-size:.8125rem;opacity:.75;overflow-wrap:anywhere;min-height:1.3em}'
            . '.mediaaudit-wait progress{width:100%;height:10px;margin:0 0 8px;accent-color:#2a78d6}'
            . '.mediaaudit-wait-spinner{display:inline-block;width:44px;height:44px;border-radius:50%;border:4px solid rgba(128,128,128,.3);'
            . 'border-top-color:#2a78d6;animation:mediaaudit-wait-turn 1s linear infinite}'
            . '@keyframes mediaaudit-wait-turn{to{transform:rotate(360deg)}}'
            . '@media (prefers-reduced-motion:reduce){.mediaaudit-wait-spinner{animation-duration:6s}}'
            . '.mediaaudit-wait [hidden]{display:none}';
    }

    public static function script(): string
    {
        return <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var options = window.Joomla && Joomla.getOptions ? Joomla.getOptions('mediaaudit.wait') : null;
    var form = document.getElementById('adminForm') || document.querySelector('form[name="adminForm"]');

    if (!options || !form || typeof HTMLDialogElement === 'undefined') {
        return;
    }

    var dialog = null;
    var parts = {};
    var mayClose = false;
    var timers = [];
    var inPlace = false;
    var asked = '';
    var NOTE = 'mediaaudit.reloaded';

    // What the request told, after the page was loaded again for it
    try {
        var note = window.sessionStorage.getItem(NOTE);

        if (note) {
            window.sessionStorage.removeItem(NOTE);

            if (Joomla.renderMessages) {
                Joomla.renderMessages({message: note.split('\n')});
            }
        }
    } catch (error) {
    }

    function element(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text) {
            // Text of the language and names of files: as text, never as markup
            node.textContent = text;
        }

        return node;
    }

    function build() {
        dialog = element('dialog', 'mediaaudit-wait');
        dialog.setAttribute('aria-labelledby', 'mediaaudit-wait-title');

        var title = element('h2', '', options.texts.title);

        title.id = 'mediaaudit-wait-title';

        parts.stage = element('p', 'mediaaudit-wait-stage');
        parts.stage.setAttribute('role', 'status');
        parts.bar = element('progress');
        parts.bar.hidden = true;
        parts.current = element('p', 'mediaaudit-wait-current');
        parts.slow = element('p', '', options.texts.slow);
        parts.slow.hidden = true;
        parts.close = element('button', 'btn btn-secondary btn-sm', options.texts.close);
        parts.close.type = 'button';
        parts.close.hidden = true;
        parts.close.addEventListener('click', hide);

        var spinner = element('span', 'mediaaudit-wait-spinner');

        spinner.setAttribute('aria-hidden', 'true');

        [spinner, title, element('p', '', options.texts.text), parts.stage, parts.bar, parts.current, parts.slow, parts.close].forEach(function (node) {
            dialog.appendChild(node);
        });

        // The work goes on whether the window is there or not: closing it early only hides that
        dialog.addEventListener('cancel', function (event) {
            if (!mayClose) {
                event.preventDefault();
            }
        });

        document.body.appendChild(dialog);
    }

    function hide() {
        timers.forEach(clearTimeout);
        timers = [];

        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    function concerns(task) {
        return options.tasks.some(function (known) {
            return known === task || (known.charAt(0) === '.' && task.slice(-known.length) === known);
        });
    }

    function content() {
        var values = [];

        Array.prototype.forEach.call(form.elements, function (field) {
            if (field.tagName === 'TEXTAREA' || (field.tagName === 'INPUT' && /^(text|hidden|url)$/.test(field.type))) {
                values.push(field.value);
            }
        });

        // What the editors hold and have not written to their fields yet
        try {
            Object.keys((Joomla.editors && Joomla.editors.instances) || {}).forEach(function (name) {
                values.push(String(Joomla.editors.instances[name].getValue() || ''));
            });
        } catch (error) {
        }

        return values.join('\n');
    }

    function hasWork() {
        if (!options.detect) {
            return true;
        }

        var text = content();

        if (options.detect.local && /\.(?:jpe?g|png)(?=[?#"'\s)&]|$)/im.test(text)) {
            return true;
        }

        if (options.detect.external) {
            var pattern = /(?:src|href|poster)\s*=\s*["']?(?:https?:)?\/\/([^\/"'\s>:]+)/gi;
            var match;

            while ((match = pattern.exec(text)) !== null) {
                if (match[1].toLowerCase() !== window.location.hostname.toLowerCase()) {
                    return true;
                }
            }
        }

        return false;
    }

    function token() {
        var bytes = new Uint8Array(16);

        window.crypto.getRandomValues(bytes);

        return Array.prototype.map.call(bytes, function (byte) {
            return ('0' + byte.toString(16)).slice(-2);
        }).join('');
    }

    /**
     * The form was sent without loading the page. When URLs were replaced in what was stored, the
     * editor shows what is no more: the page is loaded again.
     */
    function finish(state) {
        var id = form.elements['jform[id]'] || form.elements.id;
        var replaced = parseInt(state.replaced, 10) || 0;

        hide();
        inPlace = false;

        // Only the form of an item that was there before: the one of a new item would come up empty
        if (replaced === 0 || !id || !(parseInt(id.value, 10) > 0)) {
            return;
        }

        try {
            window.sessionStorage.setItem(NOTE, [state.messages || '', options.texts.reloaded].filter(Boolean).join('\n'));
        } catch (error) {
        }

        window.location.reload();
    }

    function tell(state) {
        if (state.stage === 'done') {
            if (inPlace) {
                finish(state);
            }

            return;
        }

        var stage = options.texts['stage_' + state.stage];

        if (!stage) {
            return;
        }

        var total = parseInt(state.total, 10) || 0;
        var done = Math.min(parseInt(state.done, 10) || 0, total);

        parts.stage.textContent = stage + (total > 0 ? ' · ' + options.texts.progress.replace('%1$s', Math.min(done + 1, total)).replace('%2$s', total) : '');
        parts.bar.hidden = total === 0;
        parts.bar.max = Math.max(1, total);
        parts.bar.value = done;
        parts.current.textContent = state.current || '';
    }

    function ask(value) {
        if (!dialog.open || !window.fetch || asked !== value) {
            return;
        }

        fetch(options.url + '&token=' + value, {credentials: 'same-origin', cache: 'no-store', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (answer) {
                var state = answer && answer.data && answer.data[0];

                if (state && state.stage) {
                    tell(state);
                }
            })
            .catch(function () {
            })
            .then(function () {
                // One question at a time: the answer can take as long as the work does
                timers.push(setTimeout(function () {
                    ask(value);
                }, 1000));
            });
    }

    // The field of the token is in the form from the start: whoever reads the form to send it finds it
    var field = form.elements[options.field];

    if (!field) {
        field = element('input');
        field.type = 'hidden';
        field.name = options.field;
        form.appendChild(field);
    }

    form.addEventListener('submit', function (event) {
        var task = form.elements.task ? String(form.elements.task.value) : '';

        field.value = '';

        if (!concerns(task) || !hasWork() || !window.crypto) {
            return;
        }

        field.value = token();
        asked = field.value;

        // After the others that listen: one of them may have stopped the form
        setTimeout(function () {
            // Stopped to be sent in place, or stopped for good
            inPlace = event.defaultPrevented;

            if (inPlace && !(window.jQuery && window.jQuery.active > 0)) {
                return;
            }

            if (inPlace) {
                var value = field.value;

                window.jQuery(document).one('ajaxComplete', function () {
                    // The request is over. What it told last is there, unless it failed
                    fetch(options.url + '&token=' + value, {credentials: 'same-origin', cache: 'no-store', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                        .then(function (response) {
                            return response.ok ? response.json() : null;
                        })
                        .then(function (answer) {
                            var state = answer && answer.data && answer.data[0];

                            if (inPlace && asked === value) {
                                finish(state && state.stage === 'done' ? state : {});
                            }
                        })
                        .catch(function () {
                            hide();
                        });
                });
            }

            if (!dialog) {
                build();
            }

            mayClose = false;
            parts.stage.textContent = '';
            parts.current.textContent = '';
            parts.bar.hidden = true;
            parts.slow.hidden = true;
            parts.close.hidden = true;

            if (!dialog.open) {
                dialog.showModal();
            }

            timers.push(setTimeout(function () {
                ask(field.value);
            }, 700));

            timers.push(setTimeout(function () {
                mayClose = true;
                parts.slow.hidden = false;
                parts.close.hidden = false;
            }, 45000));
        }, 0);
    }, true);

    // Back to the page from the history of the browser: the work is long over
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            hide();
        }
    });
});
JS;
    }
}
