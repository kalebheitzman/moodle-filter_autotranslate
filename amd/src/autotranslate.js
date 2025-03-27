define(['core/ajax', 'core/notification'], function (Ajax, Notification) {
    /**
     * Initializes the autotranslate and rebuild buttons.
     */
    function init() {
        console.log('Autotranslate JavaScript module loaded and initialized.');

        // Initialize the autotranslate button.
        var autotranslateButton = document.getElementById('autotranslate-button');
        if (autotranslateButton) {
            console.log('Autotranslate button found:', autotranslateButton);
            autotranslateButton.addEventListener('click', function () {
                console.log('Autotranslate button clicked.');
                var filterParams = JSON.parse(autotranslateButton.getAttribute('data-filter-params'));
                startTask('filter_autotranslate_autotranslate', filterParams);
            });
        } else {
            console.log('Autotranslate button not found.');
        }

        // Initialize the rebuild button.
        var rebuildButton = document.getElementById('rebuild-button');
        if (rebuildButton) {
            console.log('Rebuild button found:', rebuildButton);
            rebuildButton.addEventListener('click', function () {
                console.log('Rebuild button clicked.');
                var filterParams = JSON.parse(rebuildButton.getAttribute('data-filter-params'));
                startTask('filter_autotranslate_rebuild_translations', { courseid: filterParams.courseid });
            });
        } else {
            console.log('Rebuild button not found.');
        }
    }

    /**
     * Starts a task by calling the specified webservice.
     *
     * @param {string} methodname The webservice method to call.
     * @param {object} params Parameters to pass to the webservice.
     */
    function startTask(methodname, params) {
        // Disable both buttons.
        var autotranslateButton = document.getElementById('autotranslate-button');
        var rebuildButton = document.getElementById('rebuild-button');
        if (autotranslateButton) {
            autotranslateButton.setAttribute('disabled', 'disabled');
        }
        if (rebuildButton) {
            rebuildButton.setAttribute('disabled', 'disabled');
        }

        // Show the progress bar.
        var progressContainer = document.getElementById('task-progress');
        progressContainer.style.display = 'block';
        var progressBar = progressContainer.querySelector('.progress-bar');
        progressBar.classList.remove('bg-danger'); // Reset any previous failure state
        progressBar.classList.add('bg-primary'); // Set to default blue
        progressBar.style.width = '0%';
        progressBar.setAttribute('aria-valuenow', '0');
        progressBar.textContent = '0%';

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
                var progressBar = document.querySelector('#task-progress .progress-bar');
                if (progressBar) {
                    progressBar.style.width = response.percentage + '%';
                    progressBar.setAttribute('aria-valuenow', response.percentage);
                    progressBar.textContent = response.percentage + '%';

                    // If the task failed, change the progress bar to red.
                    if (response.status === 'failed') {
                        progressBar.classList.remove('bg-primary');
                        progressBar.classList.add('bg-danger');
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
                    // Continue polling.
                    setTimeout(function () {
                        pollTaskStatus(taskid);
                    }, 5000); // Poll every 5 seconds.
                }
            },
            fail: function (error) {
                Notification.addNotification({
                    message: 'Error checking task status: ' + error.message,
                    type: 'error'
                });
                var progressBar = document.querySelector('#task-progress .progress-bar');
                if (progressBar) {
                    progressBar.classList.remove('bg-primary');
                    progressBar.classList.add('bg-danger');
                }
                resetUI();
            }
        }]);
    }

    /**
     * Resets the UI after a task completes or fails.
     */
    function resetUI() {
        var autotranslateButton = document.getElementById('autotranslate-button');
        var rebuildButton = document.getElementById('rebuild-button');
        if (autotranslateButton) {
            autotranslateButton.removeAttribute('disabled');
        }
        if (rebuildButton) {
            rebuildButton.removeAttribute('disabled');
        }

        var progressContainer = document.getElementById('task-progress');
        progressContainer.style.display = 'none';
        var progressBar = progressContainer.querySelector('.progress-bar');
        if (progressBar) {
            progressBar.style.width = '0%';
            progressBar.setAttribute('aria-valuenow', '0');
            progressBar.textContent = '0%';
        }
    }

    return {
        init: init
    };
});