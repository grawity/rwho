#!/usr/bin/perl
# rwho data collector daemon

use warnings;
use strict;
use constant PATH_UTMP => '/var/run/utmp';

use POSIX qw(signal_h);
use Linux::Inotify2;
use Sys::Hostname;
use JSON;
use LWP::UserAgent;

my $notify_url = "http://equal.cluenet.org/~grawity/rwho/server.php";
my $update_interval = 10*60;

my $my_hostname;
my $pid_periodic;

# Run code in subprocess
# $pid = forked { ... };
sub forked(&) {
	my $sub = shift;
	my $pid = fork();
	if ($pid) {return $pid} else {exit &$sub}
}

# Read the utmp file
sub ut_dump() {
	my @utmp = ();
	if (eval {require User::Utmp}) {
		while (my $ent = User::Utmp::getutxent()) {
			if ($ent->{ut_type} == User::Utmp->USER_PROCESS) {
				push @utmp, {
					user => $ent->{ut_user},
					line => $ent->{ut_line},
					host => $ent->{ut_host},
					time => $ent->{ut_time},
				};
			}
		}
		User::Utmp::endutxent();
	}
	elsif (eval {require Sys::Utmp}) {
		my $utmp = Sys::Utmp->new();
		while (my $ent = $utmp->getutent()) {
			if ($ent->user_process) {
				push @utmp, {
					user => $ent->ut_user,
					line => $ent->ut_line,
					host => $ent->ut_host,
					time => $ent->ut_time,
				};
			}
		}
		$utmp->endutent();
	}
	else {
		die "error: either User::Utmp or Sys::Utmp required\n";
	}
	return @utmp;
}

# "utmp changed" handler
sub update() {
	my @data = ut_dump();
	for (@data) {
		$_->{uid} = scalar getpwnam $_->{user};
		$_->{host} =~ s/^::ffff://;
	}
	upload("put", \@data);
}

# Upload data to server
sub upload($$) {
	my ($action, $data) = @_;
	my $ua = LWP::UserAgent->new;
	my %data = (
		host => $my_hostname,
		action => $action,
		utmp => encode_json($data),
	);
	my $resp = $ua->post($notify_url, \%data);
	if (!$resp->is_success) {
		print "error: ".$resp->status_line."\n";
	}
}

sub watch() {
	my $inotify = Linux::Inotify2->new();
	$inotify->watch(PATH_UTMP, IN_MODIFY, sub {update});
	1 while $inotify->poll;
}

sub cleanup {
	if (defined $pid_periodic) {
		kill SIGTERM, $pid_periodic;
	}
	upload("destroy", []);
}

## startup code
if (!defined $notify_url) {
	die "error: notify_url not specified\n";
}

$my_hostname = hostname;
$my_hostname =~ s/\..*$//;

$SIG{INT} = \&cleanup;
$SIG{TERM} = \&cleanup;

chdir "/";
update();
if ($update_interval) {
	$pid_periodic = forked {
		$0 = "rwhod: periodic(${update_interval}s)";
		$SIG{INT} = "DEFAULT";
		$SIG{TERM} = "DEFAULT";
		while (1) {
			sleep $update_interval;
			update();
		}
	};
}
$0 = "rwhod: inotify(".PATH_UTMP.")";
watch();
