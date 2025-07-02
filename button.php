<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8" />
    <title>Сформировать маршрут</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            margin: 20px;
        }
        h1 {
            color: #333;
        }
        button {
            padding: 10px 20px;
            font-size: 16px;
            border: none;
            cursor: pointer;
            border-radius: 5px;
            margin: 10px;
        }
        #genBtn {
            background-color: #28a745;
            color: white;
        }
        #genBtn:hover {
            background-color: #218838;
        }
        #clearBtn {
            background-color: #dc3545;
            color: white;
            display: none;
        }
        #clearBtn:hover {
            background-color: #c82333;
        }
        #resultArea {
            text-align: left;
            margin: 20px auto;
            padding: 15px;
            border: 1px solid #ccc;
            background: #f9f9f9;
            border-radius: 5px;
            width: 80%;
            max-width: 600px;
            white-space: pre-wrap;
            min-height: 50px;
        }
        a {
            display: inline;
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<h1>Сформировать маршрут</h1>

<button id="genBtn">Запустить</button>
<button id="clearBtn">Очистить</button>

<pre id="resultArea"></pre>

<script>
    document.getElementById('genBtn').addEventListener('click', function(){
        document.getElementById('resultArea').textContent = 'Генерация PDF...';
        document.getElementById('clearBtn').style.display = 'none';

        fetch('handler.php', { method: 'POST' })
            .then(resp => resp.text())
            .then(text => {
                document.getElementById('resultArea').innerHTML = text;
                document.getElementById('clearBtn').style.display = 'inline';
            })
            .catch(err => {
                console.error(err);
                document.getElementById('resultArea').textContent = 'Ошибка: ' + err;
                document.getElementById('clearBtn').style.display = 'inline';
            });
    });

    document.getElementById('clearBtn').addEventListener('click', function(){
        document.getElementById('resultArea').textContent = '';
        document.getElementById('clearBtn').style.display = 'none';
    });
</script>

</body>
</html>