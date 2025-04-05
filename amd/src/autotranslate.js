define(['core/ajax', 'core/notification'], function (Ajax, Notification) {
    /**
     * Initializes the Autotranslate button on manage.php.
     *
     * Sets up the "Autotranslate" button to queue tasks via `external.php` when clicked.
     *
     * @module filter_autotranslate/autotranslate
     */
    function init() {
        var autotranslatebutton = document.getElementById('autotranslate-button');
        if (autotranslatebutton) {
            autotranslatebutton.addEventListener('click', function () {
                var filterparams = JSON.parse(autotranslatebutton.getAttribute('data-filter-params'));
                startTask('filter_autotranslate_autotranslate', filterparams);
            });
        }
    }

    /**
     * Queues a task via the specified webservice.
     *
     * Disables the button, shows a progress bar, and calls `external.php` to start the task.
     *
     * @param {string} methodname Webservice method (e.g., 'filter_autotranslate_autotranslate').
     * @param {object} params Filter parameters from manage.php.
     */
    function startTask(methodname, params) {
        var autotranslatebutton = document.getElementById('autotranslate-button');
        if (autotranslatebutton) {
            autotranslatebutton.setAttribute('disabled', 'disabled');
        }

        var progresscontainer = document.getElementById('task-progress');
        progresscontainer.style.display = 'block';
        var progressbar = progresscontainer.querySelector('.progress-bar');
        progressbar.classList.remove('bg-danger');
        progressbar.classList.add('bg-primary');
        progressbar.style.width = '0%';
        progressbar.setAttribute('aria-valuenow', '0');
        progressbar.textContent = '0%';

        Ajax.call([{
            methodname: methodname,
            args: params,
            done: function (response) {
                if (response.success && response.taskid) {
                    Notification.addNotification({
                        message: 'Task queued successfully. Awaiting completion.',
                        type: 'success'
                    });
                    pollTaskStatus(response.taskid);
                } else {
                    Notification.addNotification({
                        message: response.message || 'Failed to queue task.',
                        type: 'error'
                    });
                    resetUI();
                }
            },
            fail: function (error) {
                Notification.addNotification({
                    message: 'Error queuing task: ' + error.message,
                    type: 'error'
                });
                resetUI();
            }
        }]);
    }

    /**
     * Polls task status and updates the progress bar.
     *
     * Calls `filter_autotranslate_task_status` every second to update UI progress.
     *
     * @param {number} taskid ID of the task to poll.
     */
    function pollTaskStatus(taskid) {
        Ajax.call([{
            methodname: 'filter_autotranslate_task_status',
            args: { taskid: taskid },
            done: function (response) {
                var progressbar = document.querySelector('#task-progress .progress-bar');
                if (progressbar) {
                    progressbar.style.width = response.percentage + '%';
                    progressbar.setAttribute('aria-valuenow', response.percentage);
                    progressbar.textContent = response.percentage + '%';

                    if (response.status === 'failed') {
                        progressbar.classList.remove('bg-primary');
                        progressbar.classList.add('bg-danger');
                    }
                }

                if (response.status === 'completed') {
                    Notification.addNotification({
                        message: 'Task completed. Reloading page...',
                        type: 'success'
                    });
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                } else if (response.status === 'failed') {
                    Notification.addNotification({
                        message: 'Task failed. Check logs for details.',
                        type: 'error'
                    });
                    resetUI();
                } else {
                    setTimeout(function () {
                        pollTaskStatus(taskid);
                    }, 1000);
                }
            },
            fail: function (error) {
                Notification.addNotification({
                    message: 'Error checking status: ' + error.message,
                    type: 'error'
                });
                var progressbar = document.querySelector('#task-progress .progress-bar');
                if (progressbar) {
                    progressbar.classList.remove('bg-primary');
                    progressbar.classList.add('bg-danger');
                }
                resetUI();
            }
        }]);
    }

    /**
     * Resets the UI after task completion or failure.
     *
     * Re-enables the button and hides/resets the progress bar.
     */
    function resetUI() {
        var autotranslatebutton = document.getElementById('autotranslate-button');
        if (autotranslatebutton) {
            autotranslatebutton.removeAttribute('disabled');
        }

        var progresscontainer = document.getElementById('task-progress');
        progresscontainer.style.display = 'none';
        var progressbar = progresscontainer.querySelector('.progress-bar');
        if (progressbar) {
            progressbar.style.width = '0%';
            progressbar.setAttribute('aria-valuenow', '0');
            progressbar.textContent = '0%';
        }
    }

    return {
        init: init
    };
});