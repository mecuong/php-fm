<?php
    define('ROOT', str_replace('\\', '/', dirname(__FILE__)));
    define('GIT_BASE', 'master');

    // Save data with the json file data.json
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        if ( $action == 'save') {
            file_put_contents('./data.json', json_encode($_POST));
        }

        if ($action == 'fileSave') {
            $saveData = json_decode(file_get_contents('php://input'), true);
            $fileName = $saveData['fileName'] ?? '';

            if (file_exists(ROOT . '/' . $fileName)) {
                @file_put_contents(ROOT . '/' . $fileName, $saveData['fileContent'] ?? '');
            }
            exit();
        }
    }

    // Store data with the json file data.json
    if (file_exists('./data.json')) {
        $dataJSON = file_get_contents('./data.json');
    } else {
        $dataJSON = '[]';
    }

    if (isset($_GET['file'])) {
        if (file_exists(ROOT . '/' . $_GET['file'])) {
            echo file_get_contents(ROOT . '/' . $_GET['file']);
        }

        exit();
    }

    if (isset($_GET['run'])) {
        // Result run as server sent event
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');

        $data = json_decode($dataJSON, true);
        $issues = $data['issues'] ?? [];
        $output = '';

        sendMsg('START');

        execCommand('cd ' . ROOT . ' && git reset --hard && git fetch && git checkout ' . GIT_BASE . ' && git pull');
        sleep(1);

        // First step Merge all branches to this base
        $mergeOutput = execCommand('git merge --no-commit ' . join(' ', array_map(function($branch) {
            return 'origin/' . $branch;
        }, $issues)));

        $lineConflicts = array_filter($mergeOutput, function($line) {
            return strpos($line, 'ERROR: content conflict in') !== false
            || strpos($line, 'CONFLICT') !== false;
        });

        $conflictFiles = array_values(array_map(function($line) {
            preg_match('/[^\s]*$/', $line, $matches);
            return $matches[0] ?? '';
        }, $lineConflicts));

        if (count($conflictFiles) > 0) {
            sendMsg('CONFLICT:' . join(',', $conflictFiles));
        }


        // Second step run ....

        sleep(1);


        sendMsg('END');
        exit;

    }

    function execCommand(string $command)
    {
        $output = [];

        if (!preg_match('/2>/', $command)) {
            $command .= ' 2>&1';
        }

        exec($command, $output);

        array_map(function($line) {
            $line = trim($line);
            if ($line) {
                sendMsg($line);
            }
        }, $output);

        ob_flush();
        flush();

        return $output;
    }

    function sendMsg($msg)
    {
        echo "data: $msg\n\n";
        ob_flush();
        flush();
    }
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GIT FORM</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
        integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css"
        integrity="sha512-nMNlpuaDPrqlEls3IX/Q56H36qvBASwb3ipuo3MxeWbsQB1881ox0cRv7UPTgBlriqoynt35KjEwgGUeUXIPnw=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<body>
    <div class="container">
        <form class="mt-5" method="POST">
            <fieldset id="list-issue">
                <legend>Issue List</legend>
            </fieldset>
            <div class="form-group row">
                <div class="btn-group col-12" role="group" aria-label="Basic example">
                    <button type="button" onclick="add()" class="btn btn-primary"><i
                            class="fas fa-plus mr-1"></i>Add</button>
                    <button name="action" value="save" type="submit" class="btn btn-large btn-success"><i
                            class="fas fa-save mr-1"></i>Save</button>
                    <button type="button" onclick="run()" class="btn btn-large btn-warning"><i
                            class="fas fa-sync mr-1"></i>Run</button>
                </div>
            </div>
        </form>
        <div id="conflict-files"></div>
        <div id="editor" style="height: 500px; overflow: hidden;"></div>
        <div class="col-12 col-md-8 offset-md-2">
            <pre id="output"></pre>
        </div>
    </div>
    <script src="https://microsoft.github.io/monaco-editor/node_modules/monaco-editor/min/vs/loader.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"
        integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/ui/1.13.0/jquery-ui.min.js"
        integrity="sha256-hlKLmzaRlE8SCJC1Kw8zoUbU8BxA+8kR3gseuKfMjxA=" crossorigin="anonymous"></script>
    <script>
        var dataJSON = < ? = $dataJSON ? > ;
        var issues = dataJSON.issues || [];
        require.config({
            paths: {
                'vs': 'https://unpkg.com/monaco-editor@latest/min/vs'
            }
        });
        var proxy = URL.createObjectURL(new Blob([`
            self.MonacoEnvironment = {
                baseUrl: 'https://unpkg.com/monaco-editor@latest/min/'
            };
            importScripts('https://unpkg.com/monaco-editor@latest/min/vs/base/worker/workerMain.js');
        `], {
            type: 'text/javascript'
        }));
        window.MonacoEnvironment = {
            getWorkerUrl: () => proxy
        };
        require(['vs/editor/editor.main'], function () {
            window.editor = monaco.editor;
            window.editor.deltaDecorations(
                this.editor.getModel().getAllDecorations(),
                [{
                    range: new monaco.Range(
                        292,
                        0,
                        295,
                        0
                    ),
                    options: {
                        isWholeLine: true,
                        className: 'rightLineDecoration',
                        marginClassName: 'rightLineDecoration'
                    }
                }]
            );
        });

        for (var i = 0; i < issues.length; i++) {
            add(issues[i]);
        }

        function add(value = '') {
            var listForm = $('#list-issue');
            var content = `<div class="form-group row">
                    <div class="col-12">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text"><i class="fa">#</i></div>
                            </div>
                            <input id="text" name="issues[]" type="text" value="${value}" class="form-control">
                            <div class="input-group-append" style="cursor:pointer" onclick="this.parentNode.parentNode.parentNode.parentNode.removeChild(this.parentNode.parentNode.parentNode)">
                                <div class="input-group-text"><i class="fa fa-minus"></i></div>
                            </div>
                        </div>
                    </div>
                </div>`;
            listForm.append(content);
        }

        function run() {
            var button = $(event.target);
            var icon = button.find('i');
            icon.addClass('fa-spin');
            $('#output').text('Running ...');
            // Use server sent event
            var eventSource = new EventSource('?run=1');
            eventSource.onmessage = function (e) {
                if (e.data === 'START') {
                    $('#output').html('');
                } else if (e.data === 'END') {
                    icon.removeClass('fa-spin');
                    eventSource.close();
                } else if (e.data.includes('CONFLICT:')) {
                    haveConflict(e.data.split('CONFLICT:')[1].split(','));
                } else {
                    $('#output').append(e.data + '<br>');
                }
            };
        }

        function haveConflict(files) {
            var conflictFileListDom = $('#conflict-files');
            conflictFileListDom.html('');
            for (var i = 0; i < files.length; i++) {
                conflictFileListDom.append(
                    `<div class="alert alert-danger">${files[i]}<i class="fas fa-pencil pull-right m-2" onclick="editFile('${files[i]}')"></i></div>`
                    );
            }
        }

        function editFile(fileName) {
            var language = fileName.split('.').pop();
            if (language == 'tpl') {
                language = 'html';
            }

            fetch(`?file=${fileName}`)
                .then(function (response) {
                    return response.text();
                })
                .then(function (text) {
                    window.editor.getModels().forEach(model => model.dispose());
                    var editorDom = document.getElementById('editor');
                    window.currentFile = fileName;
                    window.editor.create(document.getElementById('editor'), {
                        value: text,
                        language,
                        theme: 'vs-dark',
                    });
                });
        }

        document.onkeydown = function (e) {
            if (e.ctrlKey && e.keyCode === 83) {
                // Check empty conflict content
                var fileContent = window.editor.getModels()[0].getValue();

                if (!fileContent.includes('<<<<<<<') &&
                    !fileContent.includes('>>>>>>>') &&
                    !fileContent.includes('=======')) {
                    $('div.alert:contains("' + window.currentFile + '")').removeClass('alert-danger').addClass(
                        'alert-success');
                } else {
                    $('div.alert:contains("' + window.currentFile + '")').removeClass('alert-success').addClass(
                        'alert-danger');
                }

                fetch('?action=fileSave', {
                    method: 'POST',
                    body: JSON.stringify({
                        fileName: window.currentFile,
                        fileContent: window.editor.getModels()[0].getValue()
                    }),
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
            }
        };

    </script>
</body>

</html>
