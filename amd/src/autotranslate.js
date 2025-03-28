define(['core/ajax', 'core/notification'], function (Ajax, Notification) {
    /**
     * Initializes the autotranslate button on the manage page.
     */
    function init() {
        console.log('Autotranslate JavaScript module loaded and initialized.');

        // Initialize the autotranslate button.
        var autotranslatebutton = document.getElementById('autotranslate-button');
        if (autotranslatebutton) {
            console.log('Autotranslate button found:', autotranslatebutton);
            autotranslatebutton.addEventListener('click', function () {
                console.log('Autotranslate button clicked.');
                var filterparams = JSON.parse(autotranslatebutton.getAttribute('data-filter-params'));
                startTask('filter_autotranslate_autotranslate', filterparams);
            });
        } else {
            console.log('Autotranslate button not found.');
        }
    }

    /**
     * Starts a task by calling the specified webservice.
     *
     * @param {string} methodname The webservice method to call.
     * @param {object} params Parameters to pass to the webservice.
     */
    function startTask(methodname, params) {
        // Disable the autotranslate button.
        var autotranslatebutton = document.getElementById('autotranslate-button');
        if (autotranslatebutton) {
            autotranslatebutton.setAttribute('disabled', 'disabled');
        }

        // Show the progress bar.
        var progresscontainer = document.getElementById('task-progress');
        progresscontainer.style.display = 'block';
        var progressbar = progresscontainer.querySelector('.progress-bar');
        progressbar.classList.remove('bg-danger');
        progressbar.classList.add('bg-primary');
        progressbar.style.width = '0%';
        progressbar.setAttribute('aria-valuenow', '0');
        progressbar.textContent = '0%';

        // Call the webservice to queue the task.
        Ajax.call([{
            methodname: methodname,
            args: params,
            done: function (response) {
                if (response.success && response.taskid) {
                    Notification.addNotification({
                        message: 'Task queued successfully. Please wait for completion.',
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
     * Polls the task status and updates the progress bar.
     *
     * @param {number} taskid The ID of the task to poll.
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
                        message: 'Task completed successfully. Reloading page...',
                        type: 'success'
                    });
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                } else if (response.status === 'failed') {
                    Notification.addNotification({
                        message: 'Task failed. Please check logs for details.',
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
                    message: 'Error checking task status: ' + error.message,
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
     * Resets the UI after a task completes or fails.
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