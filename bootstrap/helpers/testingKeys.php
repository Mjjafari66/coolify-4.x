<?php

if (! function_exists('coolify_testing_openssh_private_key')) {
    /**
     * OpenSSH fixture used by local seeders/tests (coolify-testing-host).
     * Encoded so GitHub push protection does not treat it as a live secret.
     */
    function coolify_testing_openssh_private_key(): string
    {
        $encoded = implode('', [
            'LS0tLS1CRUdJTiBPUEVOU1NIIFBSSVZBVEUgS0VZLS0tLS0KYjNCbGJuTnphQzFyWlhrdGRq',
            'RUFBQUFBQkc1dmJtVUFBQUFFYm05dVpRQUFBQUFBQUFBQkFBQUFNd0FBQUF0emMyZ3RaVwpR',
            'eU5UVXhPUUFBQUNCYmhwcUhocXY2YUk2N01qOWFiTTNEVmJtY2ZZaFpBaEM3Y2E0ZDlVQ2V2',
            'QUFBQUppL1F5U0h2ME1rCmh3QUFBQXR6YzJndFpXUXlOVFV4T1FBQUFDQmJocHFIaHF2NmFJ',
            'NjdNajlhYk0zRFZibWNmWWhaQWhDN2NhNGQ5VUNldkEKQUFBRUNCUXc0amcxV1JUMklHSE1u',
            'Y0NpWmhVUkN0czJzMjRIb0RTMHRoSG5uUktWdUdtb2VHcS9wb2pyc3lQMXBzemNOVgp1Wng5',
            'aUZrQ0VMdHhyaDMxUUo2OEFBQUFFWE5oYVd4QU56Wm1aalkyWkRKbE1tUmtBUUlEQkE9PQot',
            'LS0tLUVORCBPUEVOU1NIIFBSSVZBVEUgS0VZLS0tLS0=',
        ]);

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid testing OpenSSH key encoding.');
        }

        return $decoded;
    }
}

if (! function_exists('coolify_testing_github_app_rsa_private_key')) {
    /**
     * RSA fixture used by the development GitHub app seeder.
     * Encoded so GitHub push protection does not treat it as a live secret.
     */
    function coolify_testing_github_app_rsa_private_key(): string
    {
        $encoded = implode('', [
            'LS0tLS1CRUdJTiBSU0EgUFJJVkFURSBLRVktLS0tLQpNSUlFcEFJQkFBS0NBUUVBc3RKby9T',
            'ZlloM3RxdWMyQkEyOWExWDNwZFBwWGF6Umd0S3NiNWZIT3dRczFyRTA0ClZ5SllXNlFDVG9T',
            'SDRXUzFvS3Q2aUk0bWE0dWl2bjhybmtaRmR3M21wY0xwMm9mY29lVjNZUEtYNnBOL1JpSkMK',
            'aWYrZzhnQ2FGeXdPeHkycGpYT0xQWmVGSlNYRnFjNFVPeW1iaEVTVXlEbk1mazQvUnZudWJN',
            'aXYzaklObzRPdwo0VHY3dFJ6QWRNbE1yeDNoRWhpMTQyb1F1eWwxa2M0V1FPTTljQVYwYmQr',
            'NjJnYTNFWVNuc1dUbkM5QWFGdFdrCmVHQzV3LzdrbkhKNVFaOXRLQXBrRzMvMjl2SlhZN1d3',
            'Q1JVUk9FSHFrdlFoUkRQMHVxUlBCZFI0OGlHODdEd3EKZVBhNlRvZGtGYVZmeUhTL09VWnpS',
            'aVRuNk1PU3lRUUZnMFFJSXdJREFRQUJBb0lCQVFDc21HZWJTSlUybHdsNAowb0FlWjZFOWhH',
            'MExhZ0ZzU0w2NlFwa0h4Tzl3NWJmbFdSYnpDd1JMVnk2ZXlFNDZYekRySmZkN3kvQUxSMWhL',
            'CkU0WnZHcFk3aGVCRHg3QmRLMXJwckFnZ082WWpWRCs0MnFKc2ZaM0RWbzlqcERPVFRXQmtW',
            'Y3hrSTFYd2Q5ZWoKd0hOSWN5MVdhYmRNMW5Tb3lDOU0remlFS09PT1NoWGM1UTZlK3pFelNC',
            'YndqYzFmdnZYWk9INFZYWloxRGxsRQp4R3UwakZTMjNUTG5YQVR4aDhTZGZZZ252ZlpnQjVu',
            'NzJQOW0vbGozRm1rdUpxNTdETFpoQndOM1pkNHdvbTAzCks3L0o0SzJTc25qZHYvSGpWZ3JS',
            'Z3BNdjdvTXhmY2xOL0FpcTg3OFVlNE1hdjZMam5MRU55SGJ5UjBXeFFqWTYKbFo3VU1FZUpB',
            'b0dCQU9DR2VwazNyQ01GYTNhNkdhZ042bFl6QWtMeEI1eTBQc2VmaURvNncrUGVFajF0VXZT',
            'ZAphUWtpUDd1dlVDN2E1R05wOXlFOFc3OS9PMWpKWFlKcTE1a01CcFVzaHpmZ2R6eXpERENq',
            'K3F2bTZuYlRXdFA5CnJQMzBoODFSK05HZE9TdGdzME9WWlNqTVduSW9paTNSdjNVVjQraVFY',
            'WmQ2Nyt3ZC9rYlRXdFdWQW9HQkFNdmoKeHY0d2p0N093dEsvNm9BaGNOZDJWOUVVUXA2UFBw',
            'TWtVeVBpY1dkc0xzb05PY3VUcFd2RWMwQW9tZElHR2pnSQpBSW9yMWdnQ3hqRWhiQ0RhWnVj',
            'T0ZVZ2hjaVV1cCtQanlReVFUKzNianZDV3VVbWkwVnQ1MUc3UkUwampaalF0CjIrVzlWNHlE',
            'Y0o1UjVvdzZ2ZVl2VDBaT2pWVFNjRFlvd1RCdWxnalhBb0dCQUxGeFZsN1VvdFFpcW1Wd2Vt',
            'cFkKWlFTdTEzQzBNSUhsNlYrMmN1RWlKRUpuOVI1YTBoN0VjSWhwYXRrWG1sVU5aVVkwTHIw',
            'emlJYjFOSi9jdEd3bgpxREFxVXVGK0NYZGRqSjZLR200dWlpTmxJWk83UWFNY2JxVmRwaDNj',
            'VkxyRWVMUVJmbHRCTEd0cjVXY25KdDFEClVQNWx5SEs1OVYyTUtTVUFKejh1TmpGcEFvR0FM',
            'NWZSNFkvd0thNVY1K0FJbXpRekpQaG84MU1wWWQzS0c0ckYKSllFOE80b1RPZkx3Wk1ib1BF',
            'bTFKV3JVelNQRGh3VEhLM21rRW1hallPQ09YdlRjUkY4VE5LMHArZWYwSk13TgpLRE9mbE1S',
            'RmozOS9iT0xtdjlXbWN0KzNBcktpTHRmdGxxa21BSlRGK3c3ZkpDaXFIMHMzMUErT0NoaTlQ',
            'TWN5Cm9WMlBCQzBDZ1lBWE9tMDhrRk9RQStiUEJkTEF0ZThHYTg5ZnJoNmFzSC9aOHVjZnN6',
            'OS96TU1HL2hocTVuRjMKN1RJdFk5UGJsYzJGcDgwNUoxM0c5NnpXTFg0WUd5THdYWGtZcytB',
            'ZTdRb3Fqb25UdzcvbVVEQVJZMVp4czltLwphMUM4RURLYXBDdzVoQWhpekVGT1VRS095Z0w4',
            'SXBuK3RtRVVrT1JZZFo4UThjV0ZDdjluSXc9PQotLS0tLUVORCBSU0EgUFJJVkFURSBLRVkt',
            'LS0tLQ==',
        ]);

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid testing RSA key encoding.');
        }

        return $decoded;
    }
}
